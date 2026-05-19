<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Middleware\RateLimiter;

/**
 * Rate limiter that never rejects requests.
 *
 * Useful as a default/no-op implementation or in tests.
 */
final class UnlimitedRateLimiter implements RateLimiterInterface
{
    public function allowRequest(string $identifier): bool
    {
        return true;
    }
}
