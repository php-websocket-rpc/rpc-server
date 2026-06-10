<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;

final class InMemoryUserProvider implements UserProvider
{
    public function __construct(
        private array $users,
    ) {}

    public function getUser(int|string $id): WebsocketUserInterface
    {
        if (!\array_key_exists($id, $this->users)) {
            throw new UserNotFoundException();
        }

        return $this->users[$id];
    }
}
