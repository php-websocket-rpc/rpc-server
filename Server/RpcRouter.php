<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Server;

use PhpWebsocketRpc\Rpc\Exception\RpcDispatchException;
use PhpWebsocketRpc\Rpc\Payload\Error;
use PhpWebsocketRpc\Rpc\Payload\Kind;
use PhpWebsocketRpc\Rpc\Payload\Payload;

/**
 * @internal
 */
final class RpcRouter
{
    /**
     * @var array<class-string<Payload>, callable> FQCN => handler
     */
    private array $handlers = [];

    /**
     * @var array<class-string<Payload>, callable> FQCN => subscribe handler
     */
    private array $subscribeHandlers = [];

    /**
     * @var array<class-string<Payload>, callable> FQCN => publish handler
     */
    private array $publishHandlers = [];

    /**
     * @param class-string<Payload> $requestClass
     * @param callable $handler fn(Payload $request, ClientSession $session): Payload
     */
    public function on(string $requestClass, callable $handler): void
    {
        if (!\is_subclass_of($requestClass, Kind\RpcRequest::class)) {
            throw new \InvalidArgumentException(\sprintf(
                'Request class %s must implement Kind\\RpcRequest',
                $requestClass,
            ));
        }

        $this->handlers[$requestClass] = $handler;
    }

    /**
     * @param class-string<Payload> $requestClass Must implement Kind\StreamOpen + Stream\StreamSubscribable
     * @param callable $handler fn(Payload $request, ClientSession $session): void
     */
    public function onSubscribe(string $requestClass, callable $handler): void
    {
        $this->subscribeHandlers[$requestClass] = $handler;
    }

    /**
     * @param class-string<Payload> $requestClass Must implement Kind\StreamData + Stream\StreamChannelAware
     * @param callable $handler fn(Payload $data, ClientSession $session): void
     */
    public function onPublish(string $requestClass, callable $handler): void
    {
        $this->publishHandlers[$requestClass] = $handler;
    }

    public function dispatch(Payload $payload, ClientSession $session): ?Payload
    {
        return match (true) {
            $payload instanceof Kind\RpcRequest => $this->invokeHandler($this->handlers, $payload, $session),
            $payload instanceof Kind\StreamOpen => $this->invokeVoidHandler(
                $this->subscribeHandlers,
                $payload,
                $session,
            ),
            $payload instanceof Kind\StreamData => $this->invokeVoidHandler($this->publishHandlers, $payload, $session),
            $payload instanceof Kind\Notification => $this->invokeVoidHandler($this->handlers, $payload, $session),
            default => null,
        };
    }

    public function hasHandler(string $requestClass): bool
    {
        return \array_key_exists($requestClass, $this->handlers);
    }

    private function invokeHandler(array &$handlers, Payload $payload, ClientSession $session): ?Payload
    {
        $class = $payload::class;
        $handler = $handlers[$class] ?? $handlers['*'] ?? null;

        if ($handler === null) {
            throw new RpcDispatchException(\sprintf('No handler registered for %s', $class), Error::METHOD_NOT_FOUND);
        }

        return $handler($payload, $session);
    }

    private function invokeVoidHandler(array &$handlers, Payload $payload, ClientSession $session): null
    {
        $class = $payload::class;
        $handler = $handlers[$class] ?? null;

        if ($handler !== null) {
            $handler($payload, $session);
        }

        return null;
    }
}
