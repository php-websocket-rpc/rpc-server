<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\User;
use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;

/**
 * Simple in-memory authentication provider for testing/development.
 *
 * Maps pre-configured tokens directly to user identities.
 * Not suitable for production — use JWT or a database-backed provider instead.
 *
 * Usage:
 *   $server->useAuthentication(new BasicAuthenticationProvider([
 *       'secret-token-123' => ['id' => 'user-1', 'roles' => ['customer']],
 *       'admin-token-456'  => ['id' => 'admin-1', 'roles' => ['admin']],
 *   ]));
 */
final class BasicAuthenticationProvider implements AuthenticationProvider
{
    /**
     * @param array<string, array{id: string, roles: list<string>}> $users Map of token → user data
     */
    public function __construct(
        private readonly array $users,
    ) {}

    public function validateToken(#[\SensitiveParameter] string $token): ?WebsocketUserInterface
    {
        if (!\array_key_exists($token, $this->users)) {
            return null;
        }

        $data = $this->users[$token];

        return new User($data['id'], $data['roles']);
    }
}
