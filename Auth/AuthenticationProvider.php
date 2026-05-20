<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;

/**
 * Interface for user-provided authentication logic.
 *
 * Implement this interface to validate tokens (JWT, session ID, API key, etc.)
 * and return a normalized WebsocketUserInterface on success, or null on failure.
 *
 * Start with an in-memory implementation (e.g. BasicAuthenticationProvider)
 * then swap to a database-backed version later without changing the rest
 * of your application.
 *
 * Usage:
 *   $server->useAuthentication(new MyJwtProvider());
 *
 * @see BasicAuthenticationProvider A simple in-memory provider for development
 */
interface AuthenticationProvider
{
    /**
     * Validate an authentication token and return the corresponding user.
     *
     * This method MUST be stateless to support multiple server replicas.
     * Use JWT (self-contained) or look up from a shared store (Redis/DB).
     *
     * @param string $token The raw token sent by the client
     *
     * @return WebsocketUserInterface|null The authenticated user, or null to reject
     */
    public function validateToken(#[\SensitiveParameter] string $token): ?WebsocketUserInterface;
}
