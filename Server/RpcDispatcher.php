<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Server;

use PhpWebsocketRpc\Rpc\Middleware\MiddlewarePipeline;
use PhpWebsocketRpc\Rpc\Payload\Payload;

final readonly class RpcDispatcher implements RpcDispatcherInterface
{
    public function __construct(
        private MiddlewarePipeline $middlewarePipeline,
        private RpcRouter $router,
    ) {}

    public function dispatch(Payload $payload, ClientSession $session): ?Payload
    {
        if ($this->middlewarePipeline->count() === 0) {
            return $this->router->dispatch($payload, $session);
        }

        return $this->middlewarePipeline->execute(
            $payload,
            fn(Payload $payload, ClientSession $session): ?Payload => $this->router->dispatch($payload, $session),
            $session,
        );
    }
}
