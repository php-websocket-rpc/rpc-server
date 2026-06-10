<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Exception\RpcDispatchException;

final class UserNotFoundException extends RpcDispatchException
{
    public function __construct(
        string $message = 'User not found',
        int $rpcCode = -32_013,
        ?array $data = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $rpcCode, $data, $previous);
    }
}
