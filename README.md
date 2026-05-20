# php-websocket-rpc/rpc-server

Async RPC server over WebSocket using amphp and msgpack.

## Install

```bash
composer require php-websocket-rpc/rpc-server
```

Requires PHP 8.5+, `ext-msgpack`, and the amphp ecosystem.

## Quick Start

```php
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket\InternetAddress;
use Monolog\Logger;
use PhpWebsocketRpc\RpcServer\Server\RpcServer;

// Create HTTP server
$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose(new InternetAddress('127.0.0.1', 9502));

$router = new Router($httpServer, $logger, new DefaultErrorHandler());

// Attach RPC server at /rpc
$server = RpcServer::attach($httpServer, $router, '/rpc', $logger);

// ─── Register a typed handler ───

$server->on(MathDivideRequest::class, function (MathDivideRequest $r) {
    return new MathDivideResponse(result: $r->x / $r->y);
});

// ─── Or use contract services ───

$server->registerService(MathService::class, new MathServiceImpl());

// ─── Start ───

$httpServer->start($router, new DefaultErrorHandler());
$server->start();
```

## Features

- **Typed handlers** — register handlers by payload class
- **Contract services** — register interface implementations, auto-dispatched via `ContractRegistry`
- **Streaming** — methods returning `Iterator` are automatically streamed to the client
- **Subscribe/Publish** — `#[RpcSubscribe]` and `#[RpcPublish]` attributes on interface methods
- **Authentication** — `useAuthentication()` with pluggable providers, `#[NeedAuthorization]` attribute
- **Middleware** — pipeline for rate limiting, logging, auth, etc.
- **Client sessions** — track connected clients with per-session attributes

## Authentication & Authorization

### Quick Setup

```php
use PhpWebsocketRpc\RpcServer\Auth\BasicAuthenticationProvider;

// 1. Configure authentication provider
$server->useAuthentication(new BasicAuthenticationProvider([
    'tok-alice' => ['id' => 'alice', 'roles' => ['customer']],
    'tok-admin' => ['id' => 'bob',  'roles' => ['admin']],
]));

// 2. Protected services — methods need authentication
$server->registerService(SecureDataService::class, new SecureDataServiceImpl());
```

### Protecting Methods

Use the `#[NeedAuthorization]` attribute on your contract interface:

```php
use PhpWebsocketRpc\Rpc\Contract\Attribute\NeedAuthorization;

// Protect entire interface — all methods require auth
#[NeedAuthorization]
interface AdminService
{
    public function deleteUser(string $id): void;
}

// Protect specific methods only
interface ChatService
{
    public function getPublicInfo(): string;  // open to all

    #[NeedAuthorization]
    public function getProfile(): string;     // needs auth

    #[NeedAuthorization(roles: ['admin', 'moderator'])]
    public function deleteMessage(string $id): void;  // needs specific role
}
```

### Client Flow

```php
// 1. Authenticate
$auth = $client->createProxy(AuthService::class);
$user = $auth->authenticate('tok-alice');

// 2. Access protected methods
$service = $client->createProxy(ChatService::class);
$service->getProfile();                    // ✅ works after auth
$service->deleteMessage('msg-1');          // ❌ AuthorizationException (not admin)
```

### Custom Authentication Provider

Implement `AuthenticationProvider` to use JWT, database, or any other logic:

```php
use PhpWebsocketRpc\Rpc\Auth\User;
use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;
use PhpWebsocketRpc\RpcServer\Auth\AuthenticationProvider;

class JwtProvider implements AuthenticationProvider
{
    public function __construct(private string $secret) {}

    public function validateToken(string $token): ?WebsocketUserInterface
    {
        try {
            $payload = \Firebase\JWT\JWT::decode($token, $this->secret, ['HS256']);
            return new User($payload->sub, $payload->roles ?? []);
        } catch (\Throwable) {
            return null;
        }
    }
}

$server->useAuthentication(new JwtProvider());
```

### Custom Authorization Provider

For fine-grained authorization (resource ownership, IP checks, etc.):

```php
use PhpWebsocketRpc\Rpc\Auth\WebsocketUserInterface;
use PhpWebsocketRpc\RpcServer\Auth\AuthorizationProvider;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;

class OwnershipProvider implements AuthorizationProvider
{
    public function authorize(
        WebsocketUserInterface $user,
        ClientSession $session,
        string $service,
        string $method,
        ?array $requiredRoles,
    ): void {
        if ($method === 'deleteMessage') {
            // Check resource ownership from session attribute
        }
    }
}

$server->useAuthentication(new JwtProvider(), new OwnershipProvider());
```

### Statelessness

The `AuthenticationProvider::validateToken()` method **must be stateless** for cross-replica deployments. Use JWT (self-contained) or look up from a shared store (Redis/DB). The framework only stores the authenticated user per-WebSocket-connection in memory, which is freed on disconnect.

## Contract Services

Define an interface with attributes:

```php
use PhpWebsocketRpc\Rpc\Contract\Attribute\RpcSubscribe;
use PhpWebsocketRpc\Rpc\Contract\Attribute\RpcStream;
use PhpWebsocketRpc\Rpc\Contract\Attribute\RpcPublish;

interface MathService
{
    public function add(int $a, int $b): int;         // call/response
    public function log(string $msg): void;            // notification

    #[RpcStream]
    public function count(int $limit): \Iterator;      // streaming

    #[RpcSubscribe('events')]
    public function onEvent(callable $cb): void;       // subscribe

    #[RpcPublish('chat')]
    public function send(string $msg): void;           // publish
}
```

Register the implementation:

```php
$server->registerService(MathService::class, new MathServiceImpl());
```

## Key Classes

| Class | Purpose |
|-------|---------|
| `PhpWebsocketRpc\RpcServer\Server\RpcServer` | Main server — attach to HTTP server, register handlers |
| `PhpWebsocketRpc\RpcServer\Server\RpcRouter` | Routes incoming payloads to registered handlers |
| `PhpWebsocketRpc\RpcServer\Server\ContractRegistry` | Manages contract service implementations |
| `PhpWebsocketRpc\RpcServer\Server\ClientSession` | Represents a connected client |
| `PhpWebsocketRpc\RpcServer\Stream\StreamChannel` | Manages a named stream channel |
| `PhpWebsocketRpc\RpcServer\Middleware\RateLimiterMiddleware` | Rate limiting middleware |
| `PhpWebsocketRpc\RpcServer\Auth\AuthenticationProvider` | Interface for token validation |
| `PhpWebsocketRpc\RpcServer\Auth\AuthorizationProvider` | Interface for fine-grained authorization |
| `PhpWebsocketRpc\RpcServer\Auth\BasicAuthenticationProvider` | Simple in-memory auth provider |
| `PhpWebsocketRpc\RpcServer\Auth\AuthService` | Built-in auth handler (auto-registered) |
| `PhpWebsocketRpc\RpcServer\Auth\ClientSessionContext` | Fiber-safe session accessor |
