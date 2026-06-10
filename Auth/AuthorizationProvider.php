<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\RpcServer\Server\ClientSession;

interface AuthorizationProvider
{
    public function authorize(ClientSession $session, string $service, string $method, ?array $requiredRoles): void;
}
