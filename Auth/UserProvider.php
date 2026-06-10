<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;

interface UserProvider
{
    /**
     * @throws UserNotFoundException
     */
    public function getUser(string|int $id): WebsocketUserInterface;
}
