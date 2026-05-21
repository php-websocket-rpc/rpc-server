<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Middleware\RateLimiter;

interface RateLimiterInterface
{
    public function allowRequest(string $identifier): bool;
}
