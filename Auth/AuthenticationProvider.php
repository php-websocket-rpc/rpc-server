<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\Token;

interface AuthenticationProvider
{
    public function validateToken(#[\SensitiveParameter] string $token): ?Token;

    public function refreshToken(#[\SensitiveParameter] Token $token): Token;
}
