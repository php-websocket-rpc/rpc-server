<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\Token;
use PhpWebsocketRpc\Rpc\Contract\AuthService as AuthServiceContract;
use PhpWebsocketRpc\Rpc\Exception\AuthenticationException;
use PhpWebsocketRpc\RpcServer\Server\ClientSessionContext;

/**
 * Auto-registered when RpcServer::useAuthentication() is called.
 *
 * @internal
 */
final readonly class AuthService implements AuthServiceContract
{
    public function __construct(
        private AuthenticationProvider $provider,
        private UserProvider $userProvider,
    ) {}

    public function authenticate(#[\SensitiveParameter] string $token): Token
    {
        $userToken = $this->provider->validateToken($token);

        $this->validateToken($userToken);

        $session = ClientSessionContext::current();
        $session?->setAttribute(Attribute::USER_TOKEN->value, $userToken);
        $session?->setAttribute(Attribute::USER->value, $this->userProvider->getUser($userToken->subject));

        return $userToken;
    }

    public function refresh(#[\SensitiveParameter] string $token): Token
    {
        $userToken = $this->provider->validateToken($token);

        $this->validateToken($userToken);

        $userToken = $this->provider->refreshToken($userToken);
        $session = ClientSessionContext::current();
        $session?->setAttribute(Attribute::USER_TOKEN->value, $userToken);
        $session?->setAttribute(Attribute::USER->value, $this->userProvider->getUser($userToken->subject));

        return $userToken;
    }

    public function logout(): void
    {
        $session = ClientSessionContext::current();
        $session?->setAttribute(Attribute::USER_TOKEN->value, null);
        $session?->setAttribute(Attribute::USER->value, null);
    }

    private function validateToken(#[\SensitiveParameter] ?Token $token = null): void
    {
        match (true) {
            $token === null => throw new AuthenticationException('Invalid or expired token'),
            $token->notBefore > \time() => throw new AuthenticationException('Token not yet valid'),
            $token->expiresAt < \time() => throw new AuthenticationException('Token expired'),
            default => null,
        };
    }
}
