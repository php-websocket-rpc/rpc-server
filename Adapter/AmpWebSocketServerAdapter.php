<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Adapter;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketAcceptor;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use PhpWebsocketRpc\RpcServer\Server\ClientSession;
use PhpWebsocketRpc\RpcServer\Server\RpcServer;
use Psr\Log\LoggerInterface;

final readonly class AmpWebSocketServerAdapter implements WebsocketClientHandler
{
    private function __construct(
        private RpcServer $server,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param RpcServer              $server   The configured RPC server
     * @param WebsocketAcceptor|null $acceptor WebSocket upgrade acceptor (default: Rfc6455Acceptor)
     */
    public static function attach(
        SocketHttpServer $httpServer,
        Router $router,
        string $path,
        RpcServer $server,
        ?WebsocketAcceptor $acceptor = null,
    ): self {
        $logger = $server->getLogger();
        $adapter = new self($server, $logger);
        $websocket = new Websocket($httpServer, $logger, $acceptor ?? new Rfc6455Acceptor(), $adapter);
        $router->addRoute('GET', $path, $websocket);

        return $adapter;
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $wrappedClient = new \PhpWebsocketRpc\Rpc\Transport\Amp\Client($client);
        $dispatcher = $this->server->createDispatcher();
        $session = new ClientSession($wrappedClient, $dispatcher, $this->logger);

        $this->server->handleClient($session);
    }
}
