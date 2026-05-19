<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Middleware;

use PhpWebsocketRpc\Rpc\Exception\RateLimitException;
use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\RpcServer\Middleware\RateLimiter\RateLimiterInterface;
use PhpWebsocketRpc\RpcServer\Middleware\RateLimiter\UnlimitedRateLimiter;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;
use Psr\Log\LoggerInterface;

/**
 * Middleware that rate-limits incoming RPC requests.
 *
 * Wraps a RateLimiterInterface and delegates the allow/deny decision.
 * On deny, throws a RateLimitException which is caught by the
 * ClientSession and sent back as a proper RPC error response.
 *
 * Usage:
 *   $server->use(new RateLimiterMiddleware(
 *       rateLimiter: new SoftLimitRateLimiter(maxRequests: 100, windowSeconds: 1),
 *       logger: $logger,
 *   ));
 *
 * Custom identifier (e.g. by IP from session attributes):
 *   $server->use(new RateLimiterMiddleware(
 *       rateLimiter: new SoftLimitRateLimiter(30, 1),
 *       identifierFactory: fn(ClientSession $s) =>
 *           $s->getAttribute('remote_ip') ?? (string) $s->getClientId(),
 *       logger: $logger,
 *   ));
 */
final class RateLimiterMiddleware implements ServerMiddlewareInterface
{
    /**
     * @param RateLimiterInterface $rateLimiter       The rate limiting algorithm
     * @param \Closure(ClientSession): string|null $identifierFactory
     *        Optional callable to derive the client identifier from the session.
     *        Defaults to (string) $session->getClientId()
     * @param LoggerInterface|null $logger            Optional PSR-3 logger
     */
    public function __construct(
        private readonly RateLimiterInterface $rateLimiter = new UnlimitedRateLimiter(),
        private readonly ?\Closure $identifierFactory = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function handle(Payload $payload, ClientSession $session, callable $next): ?Payload
    {
        $identifier = $this->resolveIdentifier($session);

        if (!$this->rateLimiter->allowRequest($identifier)) {
            $this->logger?->warning('Rate limit exceeded', [
                'client_id' => $session->getClientId(),
                'identifier' => $identifier,
                'payload_type' => $payload::class,
                'payload_id' => $payload->id,
            ]);

            throw new RateLimitException(
                message: 'Too many requests. Please slow down.',
            );
        }

        return $next($payload, $session);
    }

    private function resolveIdentifier(ClientSession $session): string
    {
        return $this->identifierFactory
            ? ($this->identifierFactory)($session)
            : (string) $session->getClientId();
    }
}
