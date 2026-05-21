<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;
use PhpWebsocketRpc\Rpc\Contract\AuthService as AuthServiceContract;
use PhpWebsocketRpc\Rpc\Exception\AuthenticationException;

/**
 * Auto-registered when RpcServer::useAuthentication() is called.
 *
 * @internal
 */
final class AuthService implements AuthServiceContract
{
    public const string USER = '_auth_user';

    public function __construct(
        private readonly AuthenticationProvider $provider,
    ) {}

    public function authenticate(#[\SensitiveParameter] string $token): WebsocketUserInterface
    {
        $user = $this->provider->validateToken($token);

        if ($user === null) {
            throw new AuthenticationException('Invalid or expired token');
        }

        ClientSessionContext::current()?->setAttribute(self::USER, $user);

        return $user;
    }

    public function logout(): void
    {
        ClientSessionContext::current()?->setAttribute(self::USER, null);
    }
}
