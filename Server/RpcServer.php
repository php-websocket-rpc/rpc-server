<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Server;

use PhpWebsocketRpc\Rpc\Middleware\MiddlewarePipeline;
use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\RpcServer\Auth\Attribute;
use PhpWebsocketRpc\RpcServer\Stream\StreamChannel;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class RpcServer
{
    /** @var array<string, StreamChannel> */
    private array $channels = [];

    /** @var \SplObjectStorage<ClientSession, null> */
    private readonly \SplObjectStorage $sessions;

    public function __construct(
        private readonly RpcRouter $router,
        private readonly MiddlewarePipeline $middlewarePipeline,
        private readonly LoggerInterface $logger,
    ) {
        $this->sessions = new \SplObjectStorage();
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function createDispatcher(): RpcDispatcherInterface
    {
        return new RpcDispatcher($this->middlewarePipeline, $this->router);
    }

    public function channel(string $name): StreamChannel
    {
        return $this->channels[$name] ??= new StreamChannel($name);
    }

    public function push(string $channelName, Payload $data): void
    {
        $channel = $this->channels[$channelName] ?? null;

        if ($channel !== null) {
            $channel->push($data);
        }
    }

    public function handleClient(ClientSession $session): void
    {
        $this->sessions->offsetSet($session);

        $session->onStreamClose(function (string $channel) use ($session): void {
            $this->channel($channel)->unsubscribe($session);
            $this->log('info', 'Client unsubscribed from channel', [
                'client_id' => $session->getClientId(),
                'channel' => $channel,
            ]);
        });

        $session->onDisconnect(function () use ($session): void {
            $this->sessions->offsetUnset($session);
            foreach ($this->channels as $channel) {
                $channel->unsubscribe($session);
            }
        });

        $session->start();
    }

    public function cleanupExpiredSessions(): void
    {
        foreach ($this->sessions as $session) {
            if ($session->isClosed()) {
                continue;
            }

            $token = $session->getAttribute(Attribute::USER_TOKEN->value);

            if ($token !== null && \time() > $token->expiresAt) {
                $session->close();
                $this->sessions->offsetUnset($session);
                foreach ($this->channels as $channel) {
                    $channel->unsubscribe($session);
                }
            }
        }
    }

    public function stop(): void
    {
        foreach ($this->channels as $channel) {
            $channel->close();
        }

        foreach ($this->sessions as $session) {
            $session->close();
        }

        $this->sessions->removeAll($this->sessions);
        $this->channels = [];

        $this->log(LogLevel::INFO, 'RPC WebSocket server stopped');
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, '[RpcServer] ' . $message, $context);
    }
}
