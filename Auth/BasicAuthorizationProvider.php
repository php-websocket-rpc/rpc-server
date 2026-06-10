<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Auth;

use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;
use PhpWebsocketRpc\Rpc\Exception\AuthorizationException;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;

final readonly class BasicAuthorizationProvider implements AuthorizationProvider
{
    public function authorize(ClientSession $session, string $service, string $method, ?array $requiredRoles): void
    {
        if ($requiredRoles === null || \count($requiredRoles) === 0) {
            return;
        }
        /** @var WebsocketUserInterface $user */
        $user = $session->getAttribute(Attribute::USER->value);
        $userRoles = $user->getRoles();

        $hasRole = \count(\array_intersect($requiredRoles, $userRoles)) > 0;

        if (!$hasRole) {
            throw new AuthorizationException(
                \sprintf(
                    'Requires one of roles: %s. User has: %s',
                    \implode(', ', $requiredRoles),
                    \implode(', ', $userRoles),
                ),
                -32_011,
                ['required_roles' => $requiredRoles, 'user_roles' => $userRoles],
            );
        }
    }
}
