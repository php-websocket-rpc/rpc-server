<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;

/**
 * Simple value-object implementation of WebsocketUserInterface.
 *
 * Used internally by BasicAuthenticationProvider and suitable for
 * custom providers that want a lightweight user object without
 * database entity overhead.
 *
 * @internal
 */
final class BasicUser implements WebsocketUserInterface
{
    /**
     * @param string       $id    Unique user identifier
     * @param list<string> $roles User roles
     */
    public function __construct(
        public readonly string $id,
        public readonly array $roles,
    ) {}

    public function getUniqueIdentifier(): string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }
}
