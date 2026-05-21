<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;

interface AuthorizationProvider
{
    public function authorize(
        WebsocketUserInterface $user,
        ClientSession $session,
        string $service,
        string $method,
        ?array $requiredRoles,
    ): void;
}
