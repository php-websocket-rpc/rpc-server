<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;
use PhpWebsocketRpc\Rpc\Contract\AuthService as AuthServiceContract;
use PhpWebsocketRpc\Rpc\Exception\AuthenticationException;

/**
 * Built-in server-side implementation of the AuthService contract.
 *
 * Delegates token validation to the user-provided AuthenticationProvider,
 * stores the resulting user object in the calling client's session, and
 * returns the user data to the client.
 *
 * Auto-registered when RpcServer::useAuthentication() is called.
 *
 * @internal
 */
final class AuthService implements AuthServiceContract
{
    public function __construct(
        private readonly AuthenticationProvider $provider,
    ) {}

    public function authenticate(#[\SensitiveParameter] string $token): WebsocketUserInterface
    {
        $user = $this->provider->validateToken($token);

        if ($user === null) {
            throw new AuthenticationException('Invalid or expired token');
        }

        ClientSessionContext::current()?->setAttribute('_auth_user', $user);

        return $user;
    }

    public function logout(): void
    {
        ClientSessionContext::current()?->setAttribute('_auth_user', null);
    }
}
