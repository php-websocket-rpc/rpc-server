<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\User;
use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;

final class BasicAuthenticationProvider implements AuthenticationProvider
{
    /**
     * @param array<string, array{id: string, roles: list<string>}> $users
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
