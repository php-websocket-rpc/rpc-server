<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

enum Attribute: string
{
    case USER_TOKEN = '_user_token';
    case USER = '_user';
}
