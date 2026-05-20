<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\RpcServer\Server\ClientSession;

/**
 * Fiber-safe static holder for the current ClientSession.
 *
 * Because amphp runs each connection in its own fiber, we can store
 * and retrieve the session per fiber without cross-request interference.
 *
 * The ContractRegistry pushes the session onto this context before
 * dispatching to a service method, so AuthenticationProvider and
 * AuthorizationProvider implementations can access the current
 * client session if needed.
 *
 * Usage:
 *   $session = ClientSessionContext::current();
 *   $session->setAttribute('key', $value);
 */
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
