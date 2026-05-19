<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Middleware\RateLimiter;

/**
 * Rate limiting algorithm.
 *
 * Implementations decide whether a request should be allowed
 * based on the given client identifier (e.g. connection ID, IP, token).
 *
 * The method both checks and records the request in one call.
 *
 * Usage:
 *   class MyCustomLimiter implements RateLimiterInterface { ... }
 */
interface RateLimiterInterface
{
    /**
     * Check if a request is allowed and record it.
     *
     * @param string $identifier A unique identifier for the client
     *                           (connection ID, IP address, auth token, etc.)
     *
     * @return bool true if the request is allowed, false if rate limited
     */
    public function allowRequest(string $identifier): bool;
}
