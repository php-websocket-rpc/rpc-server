<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Server;


use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketAcceptor;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use PhpWebsocketRpc\Rpc\Contract\Attribute\NeedAuthorization;
use PhpWebsocketRpc\Rpc\Contract\AuthService as AuthServiceContract;
use PhpWebsocketRpc\Rpc\Contract\ContractInvocation;
use PhpWebsocketRpc\Rpc\Contract\ContractPublish;
use PhpWebsocketRpc\Rpc\Contract\ContractResponse;
use PhpWebsocketRpc\Rpc\Contract\ContractStreamInvocation;
use PhpWebsocketRpc\Rpc\Exception\AuthenticationException;
use PhpWebsocketRpc\Rpc\Exception\AuthorizationException;
use PhpWebsocketRpc\Rpc\Middleware\MiddlewarePipeline;
use PhpWebsocketRpc\Rpc\Payload\Kind;
use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\RpcServer\Auth\AuthenticationProvider;
use PhpWebsocketRpc\RpcServer\Auth\AuthorizationProvider;
use PhpWebsocketRpc\RpcServer\Auth\AuthService;
use PhpWebsocketRpc\RpcServer\Middleware\ServerMiddlewareInterface;
use PhpWebsocketRpc\RpcServer\Stream\StreamChannel;
use Psr\Log\LoggerInterface;

final class RpcServer implements WebsocketClientHandler
{
    private readonly RpcRouter $router;
    private ?ContractRegistry $contractRegistry = null;
    /** @var MiddlewarePipeline<Payload, ?Payload> */
    private readonly MiddlewarePipeline $middlewarePipeline;
    /** @var array<string, StreamChannel> */
    private array $channels = [];
    /** @var \SplObjectStorage<ClientSession, null> */
    private readonly \SplObjectStorage $sessions;
    private ?Websocket $websocket = null;
    private bool $started = false;
    private ?AuthenticationProvider $authenticationProvider = null;
    private ?AuthorizationProvider $authorizationProvider = null;
    private bool $authWired = false;

    public function __construct(
        private readonly WebsocketAcceptor $acceptor,
        private readonly LoggerInterface $logger
    ) {
        $this->router = new RpcRouter();
        $this->middlewarePipeline = new MiddlewarePipeline();
        $this->sessions = new \SplObjectStorage();
    }

    public static function attach(
        SocketHttpServer $httpServer,
        Router $router,
        string $path,
        LoggerInterface $logger,
        ?WebsocketAcceptor $acceptor = null,
    ): self {
        $server = new self($acceptor ?? new Rfc6455Acceptor(), $logger);

        $server->websocket = new Websocket($httpServer, $logger, $server->acceptor, $server);

        $router->addRoute('GET', $path, $server->websocket);

        return $server;
    }

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
        $this->middlewarePipeline->use(static function (Payload $payload, callable $next, ClientSession $session) use (
            $middleware,
        ): ?Payload {
            return $middleware->handle($payload, $session, $next);
        });
    }

    public function useAuthentication(
        AuthenticationProvider $authProvider,
        ?AuthorizationProvider $authzProvider = null,
    ): void {
        $this->authenticationProvider = $authProvider;
        $this->authorizationProvider = $authzProvider;
    }

    /**
     * @param class-string $interface
     * @param object $implementation
     */
    public function registerService(string $interface, object $implementation): void
    {
        if (!\is_subclass_of($implementation::class, $interface)) {
            throw new \InvalidArgumentException("Implementation must implement interface $interface");
        }

        $this->contractRegistry ??= new ContractRegistry();
        $this->contractRegistry->register($interface, $implementation);

        if (!$this->router->hasHandler(ContractInvocation::class)) {
            $this->autoWireContractHandlers();
        }

        $this->log('info', 'Registered contract service', [
            'interface' => $interface,
            'implementation' => $implementation::class,
        ]);
    }

    private function autoWireContractHandlers(): void
    {
        $registry = $this->contractRegistry;
        \assert($registry !== null);

        // Auto-register AuthService if authentication is configured
        if ($this->authenticationProvider !== null && !$this->authWired) {
            $this->authWired = true;
            $this->registerService(AuthServiceContract::class, new AuthService($this->authenticationProvider));

            // Wire the auth middleware
            $this->use(new class($this->authorizationProvider) implements ServerMiddlewareInterface {
                public function __construct(
                    private readonly ?AuthorizationProvider $authorizationProvider,
                ) {}

                public function handle(Payload $payload, ClientSession $session, callable $next): ?Payload
                {
                    // Only intercept contract invocations
                    if (!$payload instanceof ContractInvocation) {
                        return $next($payload, $session);
                    }

                    // Always allow authenticate/logouth through
                    if ($payload->method === 'authenticate' || $payload->method === 'logout') {
                        return $next($payload, $session);
                    }

                    // Check if the target service interface has #[NeedAuthorization]
                    $needsAuth = false;
                    $requiredRoles = null;

                    try {
                        $refClass = new \ReflectionClass($payload->service);
                        $refMethod = $refClass->getMethod($payload->method);

                        // Check method-level attribute first
                        $methodAttr = $refMethod->getAttributes(NeedAuthorization::class);
                        if ($methodAttr !== []) {
                            $needsAuth = true;
                            $requiredRoles = $methodAttr[0]->newInstance()->roles;
                        }

                        // Fall back to class-level attribute
                        if (!$needsAuth) {
                            $classAttr = $refClass->getAttributes(NeedAuthorization::class);
                            if ($classAttr !== []) {
                                $needsAuth = true;
                                $requiredRoles = $classAttr[0]->newInstance()->roles;
                            }
                        }
                    } catch (\ReflectionException) {
                        // Service class not found — allow through
                    }

                    if (!$needsAuth) {
                        return $next($payload, $session);
                    }

                    // Check authentication
                    $user = $session->getAttribute(AuthService::USER);

                    if ($user === null) {
                        throw new AuthenticationException('Authentication required. Call authenticate() first.');
                    }

                    // Check authorization
                    if ($requiredRoles !== null && \count($requiredRoles) > 0) {
                        $userRoles = $user->getRoles();

                        $hasRole = \count(\array_intersect($requiredRoles, $userRoles)) > 0;

                        if (!$hasRole) {
                            throw new AuthorizationException(
                                \sprintf(
                                    'Requires one of roles: %s. User has: %s',
                                    \implode(', ', $requiredRoles),
                                    \implode(', ', $userRoles),
                                ),
                                -32_011,
                                ['required_roles' => $requiredRoles, 'user_roles' => $userRoles],
                            );
                        }
                    }

                    // Custom authorization provider
                    if ($this->authorizationProvider !== null) {
                        $this->authorizationProvider->authorize(
                            $user,
                            $session,
                            $payload->service,
                            $payload->method,
                            $requiredRoles,
                        );
                    }

                    return $next($payload, $session);
                }
            });
        }

        // Handler for call/response pattern
        $this->router->on(ContractInvocation::class, static function (
            ContractInvocation $invocation,
            ClientSession $session,
        ) use ($registry): ContractResponse {
            return $registry->dispatch($invocation, $session);
        });

        // Handler for stream/subscribe pattern
        $this->router->onSubscribe(ContractStreamInvocation::class, function (
            ContractStreamInvocation $invocation,
            ClientSession $session,
        ) use ($registry): void {
            $refMethod = $this->resolveServiceMethod($registry, $invocation);

            if ($registry->hasCallableParameter($refMethod)) {
                $registry->dispatchSubscribe($invocation, $session);
            } else {
                $registry->dispatchStream($invocation, $session);
            }
        });

        // Handler for publish pattern
        $this->router->onPublish(ContractPublish::class, static function (
            ContractPublish $publish,
            ClientSession $session,
        ) use ($registry): void {
            $registry->dispatchPublish($publish, $session);
        });

        $this->log('info', 'Auto-wired contract dispatch handlers');
    }

    private function resolveServiceMethod(
        ContractRegistry $registry,
        ContractStreamInvocation $invocation,
    ): \ReflectionMethod {
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

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $session = new ClientSession($client, $this->router, $this->middlewarePipeline, $this->logger);

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
