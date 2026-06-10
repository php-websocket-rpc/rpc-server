<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Server;

use PhpWebsocketRpc\Rpc\Payload\Payload;

interface RpcDispatcherInterface
{
    public function dispatch(Payload $payload, ClientSession $session): ?Payload;
}
