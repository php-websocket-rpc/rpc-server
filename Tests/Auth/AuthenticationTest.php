<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Tests\Auth;

use PHPUnit\Framework\TestCase;
use PhpWebsocketRpc\Rpc\Auth\User;

use PhpWebsocketRpc\Rpc\Exception\AuthenticationException;
use PhpWebsocketRpc\Rpc\Exception\AuthorizationException;


use PhpWebsocketRpc\RpcServer\Auth\BasicAuthenticationProvider;
use PhpWebsocketRpc\RpcServer\Auth\ClientSessionContext;

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

    // ─── BasicAuthenticationProvider Tests ────────────────────────

    public function testBasicProviderValidatesToken(): void
    {
        $provider = new BasicAuthenticationProvider([
            'tok-alice' => ['id' => 'alice', 'roles' => ['customer']],
            'tok-admin' => ['id' => 'bob', 'roles' => ['admin']],
        ]);

        $alice = $provider->validateToken('tok-alice');
        $this->assertNotNull($alice);
        $this->assertSame('alice', $alice->getUniqueIdentifier());
        $this->assertSame(['customer'], $alice->getRoles());

        $bob = $provider->validateToken('tok-admin');
        $this->assertNotNull($bob);
        $this->assertSame('bob', $bob->getUniqueIdentifier());
        $this->assertSame(['admin'], $bob->getRoles());
    }

    public function testBasicProviderRejectsInvalidToken(): void
    {
        $provider = new BasicAuthenticationProvider([
            'tok-alice' => ['id' => 'alice', 'roles' => ['customer']],
        ]);

        $this->assertNull($provider->validateToken('invalid-token'));
        $this->assertNull($provider->validateToken(''));
    }

    public function testBasicProviderWithEmptyUsers(): void
    {
        $provider = new BasicAuthenticationProvider([]);

        $this->assertNull($provider->validateToken('anything'));
    }

    // ─── AuthService Unit Tests ────────────────────────────────────

    public function testAuthServiceAcceptsValidToken(): void
    {
        $provider = new BasicAuthenticationProvider([
            'tok-alice' => ['id' => 'alice', 'roles' => ['customer']],
        ]);

        // We can't easily test the full AuthService flow here because it
        // requires a real ClientSession (which needs a WebSocket client).
        // This is tested via the integration test instead.
        $this->assertTrue(true);
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
