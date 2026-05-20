<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Server;

use Amp\Socket\TlsInfo;
use Amp\Websocket\WebsocketClient;
use PhpWebsocketRpc\Rpc\Exception\RpcDispatchException;
use PhpWebsocketRpc\Rpc\Middleware\MiddlewarePipeline;
use PhpWebsocketRpc\Rpc\Payload\Error;
use PhpWebsocketRpc\Rpc\Payload\Kind;
use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\Rpc\Payload\RpcResponse;
use PhpWebsocketRpc\RpcClient\Transport\FramedConnection;

final class ClientSession
{
    private readonly FramedConnection $connection;
    private readonly WebsocketClient $websocket;

    /** @var array<string, mixed> Session attributes (for middleware) */
    private array $attributes = [];

    private bool $closed = false;

    /** @var \Closure(string): void|null */
    private ?\Closure $onStreamCloseCallback = null;

    /** @var \Closure(): void|null */
    private ?\Closure $onDisconnectCallback = null;

    public function __construct(
        WebsocketClient $websocketClient,
        private readonly RpcRouter $router,
        private readonly MiddlewarePipeline $middlewarePipeline,
    ) {
        $this->connection = new FramedConnection($websocketClient);
        $this->websocket = $websocketClient;
    }

    public function start(): void
    {
        try {
            foreach ($this->connection->receiveStream() as $payload) {
                $this->handleMessage($payload);
            }
        } catch (\Throwable) {
            // Connection closed — clean up
        } finally {
            $this->closed = true;
        }

        // Notify disconnect (e.g. for channel cleanup)
        $this->onDisconnectCallback?->__invoke();
    }

    public function send(Payload $payload): void
    {
        if ($this->closed || $this->connection->isClosed()) {
            return;
        }

        $this->connection->send($payload);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->connection->close();
    }

    public function isClosed(): bool
    {
        return $this->closed || $this->connection->isClosed();
    }

    /**
     * Register a callback for when the client unsubscribes from a stream channel.
     *
     * @param \Closure(string $channel): void $callback
     */
    public function onStreamClose(\Closure $callback): void
    {
        $this->onStreamCloseCallback = $callback;
    }

    /**
     * Register a callback for when the client disconnects.
     *
     * @param \Closure(): void $callback
     */
    public function onDisconnect(\Closure $callback): void
    {
        $this->onDisconnectCallback = $callback;
    }

    public function getClientId(): int
    {
        return $this->websocket->getId();
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->websocket->getTlsInfo();
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function hasAttribute(string $name): bool
    {
        return \array_key_exists($name, $this->attributes);
    }

    public function removeAttribute(string $name): void
    {
        unset($this->attributes[$name]);
    }

    private function handleMessage(Payload $payload): void
    {
        // Handle StreamClose immediately — not routed through middleware/handlers
        if ($payload instanceof Kind\StreamClose) {
            $this->onStreamCloseCallback?->__invoke($payload->channel());
            return;
        }

        try {
            $response = $this->dispatchThroughMiddleware($payload);

            if ($response !== null) {
                // Wrap in RpcResponse and send back
                $envelope = new RpcResponse(id: $payload->id, payload: $response);
                $this->send($envelope);
            }
        } catch (RpcDispatchException $e) {
            // Known dispatch error — send error response with original exception class
            $this->sendError(
                $payload->id,
                new Error(
                    code: $e->getRpcCode(),
                    message: $e->getMessage(),
                    data: $e->getErrorData(),
                    exceptionClass: $e::class,
                ),
            );
        } catch (\Throwable $e) {
            // Unexpected error — include the original exception class
            // so the client can reconstruct it on the other side
            $this->sendError(
                $payload->id,
                new Error(
                    code: Error::INTERNAL_ERROR,
                    message: 'Internal server error: ' . $e->getMessage(),
                    data: null,
                    exceptionClass: $e::class,
                ),
            );
        }
    }

    private function dispatchThroughMiddleware(Payload $payload): ?Payload
    {
        if ($this->middlewarePipeline->count() === 0) {
            return $this->router->dispatch($payload, $this);
        }

        return $this->middlewarePipeline->execute(
            $payload,
            function (Payload $payload, ClientSession $session): ?Payload {
                return $this->router->dispatch($payload, $session);
            },
            $this, // Pass session as extra arg
        );
    }

    private function sendError(string $requestId, Error $error): void
    {
        $response = new RpcResponse(id: $requestId, payload: null, error: $error);

        $this->send($response);
    }
}
