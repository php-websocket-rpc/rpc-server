<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Middleware;

use PhpWebsocketRpc\Rpc\Contract\Attribute\NeedAuthorization;
use PhpWebsocketRpc\Rpc\Contract\ContractInvocation;
use PhpWebsocketRpc\Rpc\Contract\ContractStreamInvocation;
use PhpWebsocketRpc\Rpc\Exception\AuthenticationException;
use PhpWebsocketRpc\Rpc\Payload\Payload;
use PhpWebsocketRpc\RpcServer\Auth\Attribute;
use PhpWebsocketRpc\RpcServer\Auth\AuthorizationProvider;
use PhpWebsocketRpc\RpcServer\Auth\BasicAuthorizationProvider;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;

final readonly class AuthorizationMiddleware implements ServerMiddlewareInterface
{
    public function __construct(
        private ?AuthorizationProvider $authorizationProvider = new BasicAuthorizationProvider(),
    ) {}

    public function handle(Payload $payload, ClientSession $session, callable $next): ?Payload
    {
        if (!($payload instanceof ContractInvocation || $payload instanceof ContractStreamInvocation)) {
            return $next($payload, $session);
        }

        if ($payload->method === 'authenticate' || $payload->method === 'logout' || $payload->method === 'refresh') {
            return $next($payload, $session);
        }

        // Check if the target service interface has #[NeedAuthorization]
        $needsAuth = false;
        $requiredRoles = null;

        try {
            $refClass = new \ReflectionClass($payload->service);
            $refMethod = $refClass->getMethod($payload->method);

            // Check method-level attribute first
            $methodAttr = $refMethod->getAttributes(NeedAuthorization::class);
            if ($methodAttr !== []) {
                $needsAuth = true;
                $requiredRoles = $methodAttr[0]->newInstance()->roles;
            }

            // Fall back to class-level attribute
            if (!$needsAuth) {
                $classAttr = $refClass->getAttributes(NeedAuthorization::class);
                if ($classAttr !== []) {
                    $needsAuth = true;
                    $requiredRoles = $classAttr[0]->newInstance()->roles;
                }
            }

            // @mago-expect lint:no-empty-catch-clause
        } catch (\ReflectionException) {
            // Service class not found — allow through
        }

        if (!$needsAuth) {
            return $next($payload, $session);
        }

        // Check authentication
        $user = $session->getAttribute(Attribute::USER->value);

        if ($user === null) {
            throw new AuthenticationException('Authentication required. Call authenticate() first.');
        }

        // Custom authorization provider
        if ($this->authorizationProvider !== null) {
            $this->authorizationProvider->authorize($session, $payload->service, $payload->method, $requiredRoles);
        }

        return $next($payload, $session);
    }
}
