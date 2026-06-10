<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Tests\Auth;

use PHPUnit\Framework\TestCase;
use PhpWebsocketRpc\Rpc\Auth\Token;
use PhpWebsocketRpc\Rpc\Auth\User;
use PhpWebsocketRpc\Rpc\Exception\AuthenticationException;
use PhpWebsocketRpc\Rpc\Exception\AuthorizationException;
use PhpWebsocketRpc\RpcServer\Auth\StaticTokenAuthenticationProvider;
use PhpWebsocketRpc\RpcServer\Server\ClientSessionContext;

final class AuthenticationTest extends TestCase
{
    // ─── User Value Object Tests ──────────────────────────────────

    public function testUserReturnsIdAndRoles(): void
    {
        $user = new User('user-42', ['admin', 'customer']);

        $this->assertSame('user-42', $user->getUniqueIdentifier());
        $this->assertSame(['admin', 'customer'], $user->getRoles());
        $this->assertSame('user-42', $user->id);
        $this->assertSame(['admin', 'customer'], $user->roles);
    }

    public function testUserWithEmptyRoles(): void
    {
        $user = new User('guest', []);

        $this->assertSame('guest', $user->getUniqueIdentifier());
        $this->assertSame([], $user->getRoles());
    }

    // ─── StaticTokenAuthenticationProvider Tests ──────────────────

    public function testValidatesCorrectToken(): void
    {
        $provider = new StaticTokenAuthenticationProvider('my-secret', 'alice');

        $token = $provider->validateToken('my-secret');
        $this->assertNotNull($token);
        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame('alice', $token->subject);
        $this->assertSame('server', $token->issuer);
        $this->assertSame('client', $token->audience);
        $this->assertGreaterThan(\time(), $token->expiresAt);
    }

    public function testRejectsInvalidToken(): void
    {
        $provider = new StaticTokenAuthenticationProvider('my-secret', 'alice');

        $this->assertNull($provider->validateToken('wrong-token'));
        $this->assertNull($provider->validateToken(''));
    }

    public function testRefreshTokenReturnsNewWithUpdatedExpiry(): void
    {
        $provider = new StaticTokenAuthenticationProvider('my-secret', 'alice');
        $original = $provider->validateToken('my-secret');
        $this->assertNotNull($original);

        $refreshed = $provider->refreshToken($original);

        $this->assertSame($original->id, $refreshed->id);
        $this->assertSame($original->subject, $refreshed->subject);
        $this->assertGreaterThan(\time(), $refreshed->expiresAt);
        $this->assertSame($original->issuer, $refreshed->issuer);
    }

    public function testUsesDefaultSubjectWhenNotProvided(): void
    {
        $provider = new StaticTokenAuthenticationProvider('my-secret');

        $token = $provider->validateToken('my-secret');
        $this->assertNotNull($token);
        $this->assertSame('rpc', $token->subject);
    }

    // ─── Exception Tests ──────────────────────────────────────────

    public function testAuthenticationExceptionHasCorrectCode(): void
    {
        $e = new AuthenticationException();
        $this->assertSame(-32_010, $e->getRpcCode());
        $this->assertSame('Authentication failed', $e->getMessage());
    }

    public function testAuthenticationExceptionCustomMessage(): void
    {
        $e = new AuthenticationException('Custom auth error', -32_010, ['reason' => 'expired']);
        $this->assertSame(-32_010, $e->getRpcCode());
        $this->assertSame('Custom auth error', $e->getMessage());
        $this->assertSame(['reason' => 'expired'], $e->getErrorData());
    }

    public function testAuthorizationExceptionHasCorrectCode(): void
    {
        $e = new AuthorizationException();
        $this->assertSame(-32_011, $e->getRpcCode());
        $this->assertSame('Forbidden', $e->getMessage());
    }

    public function testAuthorizationExceptionWithRequiredRoles(): void
    {
        $e = new AuthorizationException('Admin only', -32_011, ['required_roles' => ['admin']]);
        $this->assertSame(-32_011, $e->getRpcCode());
        $this->assertSame('Admin only', $e->getMessage());
        $this->assertSame(['required_roles' => ['admin']], $e->getErrorData());
    }

    public function testAuthorizationExceptionInheritsRpcDispatch(): void
    {
        $authZ = new AuthorizationException();
        $authN = new AuthenticationException();

        $this->assertInstanceOf(\PhpWebsocketRpc\Rpc\Exception\RpcDispatchException::class, $authZ);
        $this->assertInstanceOf(\PhpWebsocketRpc\Rpc\Exception\RpcDispatchException::class, $authN);
    }

    // ─── ClientSessionContext Tests ────────────────────────────────

    public function testClientSessionContextReturnsNullWhenNotSet(): void
    {
        $this->assertNull(ClientSessionContext::current());
    }
}
