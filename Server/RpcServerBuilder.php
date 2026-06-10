<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Server;

use Amp\Interval;
use PhpWebsocketRpc\Rpc\Contract\AuthService as AuthServiceContract;
use PhpWebsocketRpc\Rpc\Contract\ContractInvocation;
use PhpWebsocketRpc\Rpc\Contract\ContractPublish;
use PhpWebsocketRpc\Rpc\Contract\ContractStreamInvocation;
use PhpWebsocketRpc\Rpc\Exception\RpcDispatchException;
use PhpWebsocketRpc\Rpc\Middleware\MiddlewarePipeline;
use PhpWebsocketRpc\Rpc\Payload\Error;
use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\RpcServer\Auth\AuthenticationProvider;
use PhpWebsocketRpc\RpcServer\Auth\AuthorizationProvider;
use PhpWebsocketRpc\RpcServer\Auth\AuthService;
use PhpWebsocketRpc\RpcServer\Auth\BasicAuthorizationProvider;
use PhpWebsocketRpc\RpcServer\Auth\UserProvider;
use PhpWebsocketRpc\RpcServer\Middleware\AuthorizationMiddleware;
use PhpWebsocketRpc\RpcServer\Middleware\ServerMiddlewareInterface;
use Psr\Log\LoggerInterface;

use function Amp\async;

final class RpcServerBuilder
{
    private ?LoggerInterface $logger = null;
    private ?RpcRouter $router = null;
    private ?MiddlewarePipeline $middlewarePipeline = null;
    private ?ContractRegistry $contractRegistry = null;
    private ?AuthenticationProvider $authProvider = null;
    private ?UserProvider $userProvider = null;
    private ?AuthorizationProvider $authzProvider = null;

    /** @var array<string, object> */
    private array $services = [];

    /** @var list<ServerMiddlewareInterface> */
    private array $middlewares = [];

    public function __construct()
    {
        $this->router = new RpcRouter();
        $this->middlewarePipeline = new MiddlewarePipeline();
        $this->contractRegistry = new ContractRegistry();
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function withRouter(RpcRouter $router): self
    {
        $this->router = $router;

        return $this;
    }

    public function withMiddlewarePipeline(MiddlewarePipeline $pipeline): self
    {
        $this->middlewarePipeline = $pipeline;

        return $this;
    }

    public function withContractRegistry(ContractRegistry $registry): self
    {
        $this->contractRegistry = $registry;

        return $this;
    }

    /**
     * @param class-string $interface
     * @param object       $implementation
     */
    public function registerService(string $interface, object $implementation): self
    {
        $this->services[$interface] = $implementation;

        return $this;
    }

    public function use(ServerMiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    public function useAuthentication(
        AuthenticationProvider $authProvider,
        UserProvider $userProvider,
        ?AuthorizationProvider $authzProvider = null,
    ): self {
        $this->authProvider = $authProvider;
        $this->userProvider = $userProvider;
        $this->authzProvider = $authzProvider ?? new BasicAuthorizationProvider();

        return $this;
    }

    public function build(): RpcServer
    {
        $logger = $this->logger ?? throw new \RuntimeException('Logger is required. Call withLogger().');

        foreach ($this->services as $interface => $impl) {
            $this->contractRegistry->register($interface, $impl);
        }

        if ($this->authProvider !== null) {
            $this->contractRegistry->register(
                AuthServiceContract::class,
                new AuthService($this->authProvider, $this->userProvider),
            );
            \array_unshift($this->middlewares, new AuthorizationMiddleware($this->authzProvider));
        }

        foreach ($this->middlewares as $mw) {
            $this->middlewarePipeline->use(
                static fn(Payload $payload, callable $next, ClientSession $session): ?Payload => $mw->handle(
                    $payload,
                    $session,
                    $next,
                ),
            );
        }

        $server = new RpcServer(router: $this->router, middlewarePipeline: $this->middlewarePipeline, logger: $logger);

        $this->autoWireContractHandlers();

        async(static function () use ($server): void {
            new Interval(60, static function () use ($server): void {
                $server->cleanupExpiredSessions();
            });
        });

        $logger->info('[RpcServer] RPC WebSocket server ready');

        return $server;
    }

    private function autoWireContractHandlers(): void
    {
        $registry = $this->contractRegistry;

        $this->router->on(ContractInvocation::class, $registry->dispatch(...));

        $this->router->onSubscribe(ContractStreamInvocation::class, static function (
            ContractStreamInvocation $invocation,
            ClientSession $session,
        ) use ($registry): void {
            $service = $registry->getService($invocation->service);

            if (!\method_exists($service, $invocation->method)) {
                throw new RpcDispatchException(
                    \sprintf('Method "%s" not found on service %s', $invocation->method, $invocation->service),
                    Error::METHOD_NOT_FOUND,
                );
            }

            $refMethod = new \ReflectionMethod($service, $invocation->method);

            if ($registry->hasCallableParameter($refMethod)) {
                $registry->dispatchSubscribe($invocation, $session);

                return;
            }

            $registry->dispatchStream($invocation, $session);
        });

        $this->router->onPublish(ContractPublish::class, $registry->dispatchPublish(...));
    }
}
