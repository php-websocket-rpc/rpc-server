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
use PhpWebsocketRpc\RpcServer\Adapter\AmpWebSocketServerAdapter;
use PhpWebsocketRpc\RpcServer\Server\RpcServerBuilder;

// Logger
$handler = new StreamHandler(\Amp\ByteStream\getStdout());
$handler->setFormatter(new ConsoleFormatter());
$logger = new Logger('server', [$handler]);

// HTTP server
$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose(new InternetAddress('127.0.0.1', 9502));

$errorHandler = new DefaultErrorHandler();
$router = new Router($httpServer, $logger, $errorHandler);

// Build RPC server
$server = (new RpcServerBuilder())
    ->withLogger($logger)
    ->registerService(MathService::class, new MathServiceImpl())
    ->build();

// Mount at /rpc
AmpWebSocketServerAdapter::attach($httpServer, $router, '/rpc', $server);

// Start HTTP server
$httpServer->start($router, $errorHandler);
```

The client then uses `createProxy()`:

```php
$math = $client->createProxy(MathService::class);
$result = $math->add(10, 5);    // 15
```

## Features

- **Contract services** — register interface implementations, auto-dispatched via `ContractRegistry`
- **Streaming** — methods returning `Iterator` are automatically streamed to the client
- **Subscribe/Publish** — `#[RpcSubscribe]` and `#[RpcPublish]` attributes on interface methods
- **Authentication** — `useAuthentication()` with pluggable providers, `#[NeedAuthorization]` attribute
- **Middleware** — pipeline for rate limiting, logging, auth, etc.
- **Client sessions** — track connected clients with per-session attributes

## Authentication & Authorization

You must implement `AuthenticationProvider` to validate tokens and `AuthorizationProvider` to authorize method calls.
The framework provides built-in implementations for testing.
The `AuthService` is automatically registered and can be used to authenticate clients.
The `ClientSessionContext` is a fiber-safe accessor for session attributes.

### Quick Setup

```php
use PhpWebsocketRpc\Rpc\Auth\User;
use PhpWebsocketRpc\RpcServer\Auth\InMemoryUserProvider;
use PhpWebsocketRpc\RpcServer\Auth\StaticTokenAuthenticationProvider;

$server = (new RpcServerBuilder())
    ->withLogger($logger)
    ->useAuthentication(
        new StaticTokenAuthenticationProvider('tok-admin', 'bob'),
        new InMemoryUserProvider([
            'bob' => new User('bob', ['admin']),
        ]),
    )
    ->registerService(SecureDataService::class, new SecureDataServiceImpl())
    ->build();
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
use PhpWebsocketRpc\Rpc\Auth\Token;
use PhpWebsocketRpc\Rpc\Auth\User;
use PhpWebsocketRpc\RpcServer\Auth\AuthenticationProvider;
use PhpWebsocketRpc\RpcServer\Auth\InMemoryUserProvider;
use PhpWebsocketRpc\RpcServer\Auth\UserProvider;

class JwtProvider implements AuthenticationProvider
{
    public function __construct(private string $secret) {}

    public function validateToken(#[\SensitiveParameter] string $token): ?Token
    {
        try {
            $payload = \Firebase\JWT\JWT::decode($token, $this->secret, ['HS256']);
            $now = \time();
            return new Token(
                id: \bin2hex(\random_bytes(16)),
                issuer: 'my-app',
                subject: $payload->sub,
                audience: 'rpc',
                expiresAt: $now + 3600,
                notBefore: $now,
                issuedAt: $now,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function refreshToken(#[\SensitiveParameter] Token $token): Token
    {
        return new Token(
            id: $token->id,
            issuer: $token->issuer,
            subject: $token->subject,
            audience: $token->audience,
            expiresAt: \time() + 3600,
            notBefore: \time(),
            issuedAt: \time(),
        );
    }
}

// Must also provide a UserProvider to resolve user details from the token subject
$server = (new RpcServerBuilder())
    ->withLogger($logger)
    ->useAuthentication(
        new JwtProvider(),
        new InMemoryUserProvider([
            'alice' => new User('alice', ['customer']),
        ]),
    )
    ->build();
```

### Custom Authorization Provider

For fine-grained authorization (resource ownership, IP checks, etc.):

```php
use PhpWebsocketRpc\RpcServer\Auth\AuthorizationProvider;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;

class OwnershipProvider implements AuthorizationProvider
{
    public function authorize(
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

$server = (new RpcServerBuilder())
    ->withLogger($logger)
    ->useAuthentication(
        new JwtProvider(),
        new InMemoryUserProvider([...]),
        new OwnershipProvider(),   // third arg = optional AuthorizationProvider
    )
    ->build();
```

### Statelessness

The `AuthenticationProvider::validateToken()` method **must be stateless** for cross-replica deployments. Use JWT (self-contained) or look up from a shared store (Redis/DB). It returns a `Token` value object (id, issuer, subject, audience, expiry) — the actual user data is resolved separately via `UserProvider::getUser()`. The framework only stores the authenticated user per-WebSocket-connection in memory, which is freed on disconnect.

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
$server = (new RpcServerBuilder())
    ->withLogger($logger)
    ->registerService(MathService::class, new MathServiceImpl())
    ->build();
```

## Architecture

The server uses a **builder + adapter** pattern to keep the core framework-agnostic:

- **`RpcServerBuilder`** collects all configuration (services, middleware, auth) and calls `build()` to produce a configured `RpcServer`
- **`RpcServer`** is the runtime: dispatches messages, manages sessions, and streams — it has zero amphp imports in its core
- **`AmpWebSocketServerAdapter`** bridges amphp's HTTP/WebSocket layer to `RpcServer`, creating `ClientSession` on upgrade and wrapping the amp client in `Client` (from `PhpWebsocketRpc\Rpc\Transport\Amp`)
- **Transport interfaces** (`WebSocketClientInterface`, `MessageInterface`, `TlsInfoInterface`) in `PhpWebsocketRpc\Rpc\Transport` define the boundary; amp implementations live in a sub-namespace

## Key Classes

| Class | Purpose |
|-------|---------|
| `PhpWebsocketRpc\RpcServer\Server\RpcServerBuilder` | Fluent builder — configure services, middleware, auth, then `build()` |
| `PhpWebsocketRpc\RpcServer\Server\RpcServer` | Runtime core — dispatches messages, manages sessions, no amphp deps |
| `PhpWebsocketRpc\RpcServer\Adapter\AmpWebSocketServerAdapter` | Bridges amphp HTTP/WebSocket to `RpcServer` |
| `PhpWebsocketRpc\RpcServer\Server\RpcDispatcher` | Composes middleware pipeline with router dispatch |
| `PhpWebsocketRpc\RpcServer\Server\ContractRegistry` | Manages contract service implementations |
| `PhpWebsocketRpc\RpcServer\Server\ClientSession` | Represents a connected client |
| `PhpWebsocketRpc\RpcServer\Stream\StreamChannel` | Manages a named stream channel |
| `PhpWebsocketRpc\RpcServer\Middleware\RateLimiterMiddleware` | Rate limiting middleware |
| `PhpWebsocketRpc\RpcServer\Auth\AuthenticationProvider` | Interface for token validation (returns `Token`) |
| `PhpWebsocketRpc\RpcServer\Auth\UserProvider` | Interface for resolving user details from a token subject |
| `PhpWebsocketRpc\RpcServer\Auth\AuthorizationProvider` | Interface for fine-grained authorization |
| `PhpWebsocketRpc\RpcServer\Auth\StaticTokenAuthenticationProvider` | Simple static-token auth provider |
| `PhpWebsocketRpc\RpcServer\Auth\InMemoryUserProvider` | Simple in-memory user provider for testing |
| `PhpWebsocketRpc\RpcServer\Auth\AuthService` | Built-in auth handler (auto-registered) |
| `PhpWebsocketRpc\RpcServer\Auth\ClientSessionContext` | Fiber-safe session accessor |
| `PhpWebsocketRpc\Rpc\Transport\Amp\Client` | Wraps amphp's `WebsocketClient` for `RpcServer` |
| `PhpWebsocketRpc\Rpc\Transport\FramedConnection` | Serializes/deserializes `Payload` over binary WebSocket |
