<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;

/**
 * Optional interface for fine-grained authorization logic.
 *
 * Called before every dispatch to a method or class marked with
 * #[NeedAuthorization]. If not provided, the framework simply checks
 * whether a user is present in the session (and whether they have
 * one of the required roles, if specified by the attribute).
 *
 * Implement this when you need custom logic beyond simple role checking,
 * such as resource ownership, IP-based restrictions, or rate limits.
 *
 * Usage:
 *   $server->useAuthentication($authProvider, new MyAuthorizationProvider());
 */
interface AuthorizationProvider
{
    /**
     * Authorize a user for a specific service method.
     *
     * Throw AuthorizationException to reject the request.
     * Return void to allow it through.
     *
     * @param WebsocketUserInterface $user          The authenticated user
     * @param ClientSession          $session       The current client session
     * @param string                 $service       The FQCN of the contract service
     * @param string                 $method        The method being called
     * @param string[]|null          $requiredRoles Roles required by #[NeedAuthorization],
     *                                              or null if none specified
     *
     * @throws \PhpWebsocketRpc\Rpc\Exception\AuthorizationException
     */
    public function authorize(
        WebsocketUserInterface $user,
        ClientSession $session,
        string $service,
        string $method,
        ?array $requiredRoles,
    ): void;
}
