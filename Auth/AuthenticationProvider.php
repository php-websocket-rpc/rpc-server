<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;

interface AuthenticationProvider
{
    public function validateToken(#[\SensitiveParameter] string $token): ?WebsocketUserInterface;
}
