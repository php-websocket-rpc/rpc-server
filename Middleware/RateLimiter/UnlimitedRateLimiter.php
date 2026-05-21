<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Middleware\RateLimiter;

final class UnlimitedRateLimiter implements RateLimiterInterface
{
    public function allowRequest(string $identifier): bool
    {
        return true;
    }
}
