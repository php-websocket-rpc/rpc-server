<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Middleware;

use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;

/**
 * Middleware interface for the RPC server.
 *
 * Intercepts requests before they reach the handler and
 * responses before they are sent back. Each middleware
 * can inspect/modify the payload, authenticate, log, etc.
 *
 * Usage:
 *   $server->use(new class implements ServerMiddlewareInterface {
 *       public function handle(Payload $payload, ClientSession $session, callable $next): Payload {
 *           // pre-handle logic
 *           $response = $next($payload, $session);
 *           // post-handle logic
 *           return $response;
 *       }
 *   });
 */
interface ServerMiddlewareInterface
{
    /**
     * @param Payload       $payload The incoming request payload
     * @param ClientSession $session The client's session
     * @param callable(Payload, ClientSession): (?Payload) $next Next in chain
     *
     * @return Payload|null The response (null for void handlers)
     */
    public function handle(Payload $payload, ClientSession $session, callable $next): ?Payload;
}
