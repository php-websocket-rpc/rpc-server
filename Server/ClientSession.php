<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Server;

use PhpWebsocketRpc\Rpc\Exception\RpcDispatchException;
use PhpWebsocketRpc\Rpc\Payload\Error;
use PhpWebsocketRpc\Rpc\Payload\Kind;
use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\Rpc\Payload\RpcResponse;
use PhpWebsocketRpc\Rpc\Transport\FramedConnection;
use PhpWebsocketRpc\Rpc\Transport\TlsInfoInterface;
use PhpWebsocketRpc\Rpc\Transport\WebSocketClientInterface;
use Psr\Log\LoggerInterface;

final class ClientSession
{
    private readonly FramedConnection $connection;
    private readonly WebSocketClientInterface $websocket;

    /** @var array<string, mixed> Session attributes (for middleware) */
    private array $attributes = [];

    private bool $closed = false;

    /** @var \Closure(string): void|null */
    private ?\Closure $onStreamCloseCallback = null;

    /** @var \Closure(): void|null */
    private ?\Closure $onDisconnectCallback = null;

    public function __construct(
        WebSocketClientInterface $websocketClient,
        private readonly RpcDispatcherInterface $dispatcher,
        private readonly ?LoggerInterface $logger = null,
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
        } catch (\Throwable $e) {
            $this->logger?->debug('Session receive error', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } finally {
            $this->closed = true;
        }

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
     * @param \Closure(string $channel): void $callback
     */
    public function onStreamClose(\Closure $callback): void
    {
        $this->onStreamCloseCallback = $callback;
    }

    /**
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

    public function getTlsInfo(): ?TlsInfoInterface
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
        if ($payload instanceof Kind\StreamClose) {
            $this->onStreamCloseCallback?->__invoke($payload->channel());

            return;
        }

        try {
            $response = $this->dispatcher->dispatch($payload, $this);

            if ($response !== null) {
                $envelope = new RpcResponse(id: $payload->id, payload: $response);
                $this->send($envelope);
            }
        } catch (RpcDispatchException $e) {
            $error = new Error(
                code: $e->getRpcCode(),
                message: $e->getMessage(),
                data: $e->getErrorData(),
                exceptionClass: $e::class,
            );
            $this->logger?->error($e->getMessage(), ['error' => $error->toArray()]);
            $this->sendError($payload->id, $error);
        } catch (\Throwable $e) {
            $this->logger?->emergency($e->getMessage(), [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'stacktrace' => $e->getTrace(),
            ]);
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

    private function sendError(string $requestId, Error $error): void
    {
        $response = new RpcResponse(id: $requestId, payload: null, error: $error);

        $this->send($response);
    }
}
