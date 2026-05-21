<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\RpcServer\Server\ClientSession;

final class ClientSessionContext
{
    /** @var array<int, ClientSession> */
    private static array $fibers = [];

    public static function set(ClientSession $session): void
    {
        self::$fibers[self::getFiberId()] = $session;
    }

    public static function current(): ?ClientSession
    {
        return self::$fibers[self::getFiberId()] ?? null;
    }

    public static function reset(): void
    {
        unset(self::$fibers[self::getFiberId()]);
    }

    private static function getFiberId(): int
    {
        $fiber = \Fiber::getCurrent();

        return $fiber !== null ? \spl_object_id($fiber) : 0;
    }
}
