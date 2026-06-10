<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\Token;

final readonly class StaticTokenAuthenticationProvider implements AuthenticationProvider
{
    public function __construct(
        #[\SensitiveParameter]
        private string $staticToken,
        private string $subject = 'rpc',
    ) {}

    public function validateToken(#[\SensitiveParameter] string $token): ?Token
    {
        // @mago-expect lint:no-insecure-comparison
        if ($token !== $this->staticToken) {
            return null;
        }

        $now = \time();
        return new Token(\bin2hex(\random_bytes(16)), 'server', $this->subject, 'client', $now + 60, $now, $now);
    }

    public function refreshToken(#[\SensitiveParameter] Token $token): Token
    {
        return new Token(
            id: $token->id,
            issuer: $token->issuer,
            subject: $token->subject,
            audience: $token->audience,
            expiresAt: \time() + 60,
            notBefore: \time(),
            issuedAt: \time(),
        );
    }
}
