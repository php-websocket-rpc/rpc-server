<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Middleware\RateLimiter;

final class SoftLimitRateLimiter implements RateLimiterInterface
{
    /**
     * @var array<string, array{window_start: float, count: int}>
     */
    private array $clients = [];

    public function __construct(
        private readonly int $maxRequests,
        private readonly int $windowSeconds = 1,
    ) {
        if ($maxRequests < 1) {
            throw new \InvalidArgumentException('maxRequests must be at least 1');
        }

        if ($windowSeconds < 1) {
            throw new \InvalidArgumentException('windowSeconds must be at least 1');
        }
    }

    public function allowRequest(string $identifier): bool
    {
        $now = \microtime(true);
        $entry = $this->clients[$identifier] ?? null;

        // First request, or window has expired — start a new window
        if ($entry === null || ($now - $entry['window_start']) > $this->windowSeconds) {
            $this->clients[$identifier] = [
                'window_start' => $now,
                'count' => 1,
            ];

            return true;
        }

        // Within the current window
        if ($entry['count'] >= $this->maxRequests) {
            return false; // Rate limited
        }

        // Allow and increment
        $entry['count']++;
        $this->clients[$identifier] = $entry;

        return true;
    }

    /**
     * Reset the rate limit state for a specific client.
     */
    public function reset(string $identifier): void
    {
        unset($this->clients[$identifier]);
    }

    /**
     * Reset all rate limit state.
     */
    public function resetAll(): void
    {
        $this->clients = [];
    }
}
