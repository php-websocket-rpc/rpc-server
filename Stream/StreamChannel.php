<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Stream;

use PhpWebsocketRpc\Rpc\Payload\Kind;
use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\Rpc\Payload\StreamClose;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;

/**
 * Usage:
 *   $server->channel('orders')->subscribe($session);
 *   $server->channel('orders')->push(new OrderEvent(orderId: 42, status: 'shipped'));
 */
final class StreamChannel
{
    private const int PUSH_CONCURRENCY = 10;

    /** @var \SplObjectStorage<ClientSession, null> */
    private readonly \SplObjectStorage $subscribers;

    private bool $closed = false {
        get {
            return $this->closed;
        }
    }

    public function __construct(
        public readonly string $name,
    ) {
        $this->subscribers = new \SplObjectStorage();
    }

    public function subscribe(ClientSession $session): void
    {
        if ($this->closed) {
            return;
        }

        $this->subscribers->offsetSet($session, null);
    }

    public function unsubscribe(ClientSession $session): void
    {
        $this->subscribers->offsetUnset($session);
    }

    /**
     * @param Kind\StreamData&Payload $data
     */
    public function push(Payload $data): void
    {
        if ($this->closed) {
            return;
        }

        $toRemove = [];
        foreach ($this->subscribers as $session) {
            if ($session->isClosed()) {
                $toRemove[] = $session;
                continue;
            }

            \Amp\async(static function () use ($session, $data): void {
                try {
                    $session->send($data);
                } catch (\Throwable) {
                    // Failed — will be cleaned up next push cycle
                }
            });
        }

        foreach ($toRemove as $session) {
            $this->subscribers->offsetUnset($session);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->subscribers as $session) {
            \Amp\async(fn() => $session->send(new StreamClose($this->name)));
        }

        $this->subscribers->removeAll($this->subscribers);
    }

    public function subscriberCount(): int
    {
        return $this->subscribers->count();
    }
}
