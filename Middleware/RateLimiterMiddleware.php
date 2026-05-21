<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Middleware;

use PhpWebsocketRpc\Rpc\Exception\RateLimitException;
use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\RpcServer\Middleware\RateLimiter\RateLimiterInterface;
use PhpWebsocketRpc\RpcServer\Middleware\RateLimiter\UnlimitedRateLimiter;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;
use Psr\Log\LoggerInterface;

final class RateLimiterMiddleware implements ServerMiddlewareInterface
{
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

            throw new RateLimitException(message: 'Too many requests. Please slow down.');
        }

        return $next($payload, $session);
    }

    private function resolveIdentifier(ClientSession $session): string
    {
        return $this->identifierFactory ? ($this->identifierFactory)($session) : (string) $session->getClientId();
    }
}
