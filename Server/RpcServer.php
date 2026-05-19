<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Server;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketAcceptor;
use Amp\Websocket\Server\WebsocketClientAcceptor;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Psr\Log\LoggerInterface;
use PhpWebsocketRpc\Rpc\Contract\ContractInvocation;
use PhpWebsocketRpc\Rpc\Contract\ContractPublish;
use PhpWebsocketRpc\Rpc\Contract\ContractResponse;
use PhpWebsocketRpc\Rpc\Contract\ContractStreamInvocation;
use PhpWebsocketRpc\Rpc\Middleware\MiddlewarePipeline;
use PhpWebsocketRpc\RpcServer\Middleware\ServerMiddlewareInterface;
use PhpWebsocketRpc\Rpc\Payload\Error;
use PhpWebsocketRpc\Rpc\Payload\Kind;
use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\Rpc\Serialization\Serializer;
use PhpWebsocketRpc\RpcServer\Stream\StreamChannel;
use PhpWebsocketRpc\Rpc\Stream\StreamChannelAware;
use PhpWebsocketRpc\Rpc\Stream\StreamSubscribable;

/**
 * Async RPC server over WebSocket.
 *
 * Attaches to an amphp HTTP server and handles WebSocket connections
 * for typed RPC calls, notifications, and streaming.
 *
 * Fully non-blocking — all request handling happens in amphp fibers.
 *
 * Usage:
 *   $server = RpcServer::attach($httpServer, $router, '/rpc', $logger);
 *
 *   $server->on(MathDivideRequest::class, function(MathDivideRequest $r) {
 *       return new MathDivideResponse(result: $r->x + $r->y);
 *   });
 *
 *   $server->start();
 */
final class RpcServer implements WebsocketClientHandler
{
    private readonly RpcRouter $router;
    private readonly Serializer $serializer;
    private ?ContractRegistry $contractRegistry = null;

    /** @var MiddlewarePipeline<Payload, ?Payload> */
    private readonly MiddlewarePipeline $middlewarePipeline;

    /** @var array<string, StreamChannel> */
    private array $channels = [];

    /** @var \SplObjectStorage<ClientSession, null> */
    private readonly \SplObjectStorage $sessions;

    private ?Websocket $websocket = null;
    private bool $started = false;

    private function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->router = new RpcRouter();
        $this->serializer = new Serializer();
        $this->middlewarePipeline = new MiddlewarePipeline();
        $this->sessions = new \SplObjectStorage();
    }

    /**
     * Attach the RPC server to an amphp HTTP server at the given path.
     *
     * @param SocketHttpServer $httpServer The amphp HTTP server instance
     * @param Router           $router     The amphp HTTP router to attach the WebSocket route to
     * @param string           $path       The WebSocket endpoint path, e.g. '/rpc'
     * @param LoggerInterface  $logger     PSR-3 logger
     * @param WebsocketAcceptor|null $acceptor Optional custom WebSocket acceptor
     */
    public static function attach(
        SocketHttpServer $httpServer,
        Router $router,
        string $path,
        LoggerInterface $logger,
        ?WebsocketAcceptor $acceptor = null,
    ): self {
        $server = new self($logger);

        $wsAcceptor = $acceptor ?? new Rfc6455Acceptor();

        $server->websocket = new Websocket(
            $httpServer,
            $logger,
            $wsAcceptor,
            $server,
        );

        $router->addRoute('GET', $path, $server->websocket);

        return $server;
    }

    // ─── Public API ────────────────────────────────────────────

    /**
     * @template TRequest of Payload
     * @template TResponse of Payload
     *
     * @param class-string<TRequest> $requestClass Must implement Kind\RpcRequest
     * @param callable(TRequest, ClientSession): TResponse $handler
     */
    public function on(string $requestClass, callable $handler): void
    {
        $this->router->on($requestClass, $handler);

        $this->log('info', 'Registered RPC handler', [
            'class' => $requestClass,
        ]);
    }

    /**
     * @template T of Payload
     * @param class-string<T> $requestClass Must implement Kind\StreamOpen + StreamSubscribable
     * @param callable(T, ClientSession): void $handler
     */
    public function onSubscribe(string $requestClass, callable $handler): void
    {
        $this->router->onSubscribe($requestClass, $handler);

        $this->log('info', 'Registered subscribe handler', [
            'class' => $requestClass,
        ]);
    }

    /**
     * @template T of Payload
     * @param class-string<T> $requestClass Must implement Kind\StreamData + StreamChannelAware
     * @param callable(T, ClientSession): void $handler
     */
    public function onPublish(string $requestClass, callable $handler): void
    {
        $this->router->onPublish($requestClass, $handler);

        $this->log('info', 'Registered publish handler', [
            'class' => $requestClass,
        ]);
    }

    /**
     * @return StreamChannel The channel instance
     */
    public function channel(string $name): StreamChannel
    {
        return $this->channels[$name] ??= new StreamChannel($name);
    }

    /**
     * Convenience shortcut for $server->channel($name)->push($data).
     *
     * @param Kind\StreamData&Payload $data
     */
    public function push(string $channelName, Payload $data): void
    {
        $channel = $this->channels[$channelName] ?? null;

        if ($channel !== null) {
            $channel->push($data);
        }
    }

    public function use(ServerMiddlewareInterface $middleware): void
    {
        $this->middlewarePipeline->use(
            function (Payload $payload, callable $next, ClientSession $session) use ($middleware): ?Payload {
                return $middleware->handle($payload, $session, $next);
            }
        );
    }

    /**
     * Register a contract service implementation.
     *
     * The first call to registerService() automatically wires the
     * contract dispatch handlers (ContractInvocation and ContractStreamInvocation).
     *
     * @param string $interface      Fully qualified interface name
     * @param object $implementation Concrete implementation
     */
    public function registerService(string $interface, object $implementation): void
    {
        $this->contractRegistry ??= new ContractRegistry();
        $this->contractRegistry->register($interface, $implementation);

        // Auto-wire contract handlers on first registration
        if (!$this->router->hasHandler(ContractInvocation::class)) {
            $this->autoWireContractHandlers();
        }

        $this->log('info', 'Registered contract service', [
            'interface' => $interface,
            'implementation' => $implementation::class,
        ]);
    }

    /**
     * Auto-wire all contract dispatch handlers.
     *
     * Called once on the first registerService() call.
     */
    private function autoWireContractHandlers(): void
    {
        $registry = $this->contractRegistry;
        \assert($registry !== null);

        // Handler for call/response pattern
        $this->router->on(
            ContractInvocation::class,
            function (ContractInvocation $invocation) use ($registry): ContractResponse {
                return $registry->dispatch($invocation);
            },
        );

        // Handler for stream/subscribe pattern
        $this->router->onSubscribe(
            ContractStreamInvocation::class,
            function (ContractStreamInvocation $invocation, ClientSession $session) use ($registry): void {
                $refMethod = $this->resolveServiceMethod($registry, $invocation);

                if ($registry->hasCallableParameter($refMethod)) {
                    $registry->dispatchSubscribe($invocation, $session);
                } else {
                    $registry->dispatchStream($invocation, $session);
                }
            },
        );

        // Handler for publish pattern
        $this->router->onPublish(
            ContractPublish::class,
            function (ContractPublish $publish, ClientSession $session) use ($registry): void {
                $registry->dispatchPublish($publish, $session);
            },
        );

        $this->log('info', 'Auto-wired contract dispatch handlers');
    }

    /**
     * Resolve the ReflectionMethod for a ContractStreamInvocation.
     */
    private function resolveServiceMethod(ContractRegistry $registry, ContractStreamInvocation $invocation): \ReflectionMethod
    {
        $service = $registry->getService($invocation->service);

        if (!\method_exists($service, $invocation->method)) {
            throw new \PhpWebsocketRpc\Rpc\Exception\RpcDispatchException(
                \sprintf('Method "%s" not found on service %s', $invocation->method, $invocation->service),
                \PhpWebsocketRpc\Rpc\Payload\Error::METHOD_NOT_FOUND,
            );
        }

        return new \ReflectionMethod($service, $invocation->method);
    }

    public function start(): void
    {
        $this->started = true;
        $this->log('info', 'RPC WebSocket server ready');
    }

    /**
     * Stop the server and close all sessions/channels.
     */
    public function stop(): void
    {
        if ($this->started === false) {
            return;
        }

        $this->started = false;

        // Close all channels
        foreach ($this->channels as $channel) {
            $channel->close();
        }

        // Close all sessions
        foreach ($this->sessions as $session) {
            $session->close();
        }

        $this->sessions->removeAll($this->sessions);
        $this->channels = [];

        $this->log('info', 'RPC WebSocket server stopped');
    }

    public function handleClient(
        WebsocketClient $client,
        Request $request,
        Response $response,
    ): void {
        $session = new ClientSession(
            $client,
            $this->router,
            $this->middlewarePipeline,
        );

        // When the client unsubscribes from a channel, remove them
        $session->onStreamClose(function (string $channel) use ($session): void {
            $this->channel($channel)->unsubscribe($session);
            $this->log('info', 'Client unsubscribed from channel', [
                'client_id' => $session->getClientId(),
                'channel' => $channel,
            ]);
        });

        $this->sessions->offsetSet($session);

        $this->log('info', 'Client connected', [
            'client_id' => $client->getId(),
            'session_count' => $this->sessions->count(),
        ]);

        $session->start();

        // Clean up after disconnect
        $this->sessions->offsetUnset($session);
        foreach ($this->channels as $channel) {
            $channel->unsubscribe($session);
        }

        $this->log('info', 'Client disconnected', [
            'client_id' => $client->getId(),
            'session_count' => $this->sessions->count(),
        ]);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, '[RpcServer] ' . $message, $context);
    }
}
