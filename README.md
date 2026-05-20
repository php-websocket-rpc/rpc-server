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
- **Middleware** — pipeline for rate limiting, logging, auth, etc.
- **Client sessions** — track connected clients with per-session attributes

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
