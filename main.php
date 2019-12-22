<?php

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Socket as SocketSocket;
use Amp\Success;
use Amp\Websocket\Client;
use Amp\Websocket\Message;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Websocket;
use Monolog\Logger;
use function Amp\ByteStream\getStdout;
use function Amp\call;
use function Amp\Socket\connect;

require __DIR__.'/vendor/autoload.php';

$ip = '67.207.74.182';
$port = 4924;

$logHandler = new StreamHandler(getStdout());
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);


class TONHandler implements ClientHandler {
    /**
     * TON validator URI.
     *
     * @var string
     */
    private $validatorUri;
    /**
     * Logger.
     *
     * @var Logger
     */
    private $logger;
    /**
     * Constructor.
     *
     * @param string  $ip     Validator IP
     * @param integer $port   Validator port
     * @param Logger  $logger Logger
     */
    public function __construct(string $ip, int $port, Logger $logger)
    {
        $this->validatorUri = "tcp://$ip:$port";
        $this->logger = $logger;
    }

    /**
     * Called when the HTTP server is started.
     *
     * @param Websocket $endpoint
     *
     * @return Promise
     */
    public function onStart(Websocket $endpoint): Promise
    {
        $this->logger->notice("Started WS server!");
        return new Success();
    }

    /**
     * Called when the HTTP server is stopped.
     *
     * @param Websocket $endpoint
     *
     * @return Promise
     */
    public function onStop(Websocket $endpoint): Promise
    {
        $this->logger->notice("Stopped WS server!");
        return new Success();
    }

    /**
     * Accept all incoming connections.
     *
     * @param \Amp\Http\Server\Request  $request
     * @param \Amp\Http\Server\Response $response
     *
     * @return \Amp\Promise
     */
    public function handleHandshake(\Amp\Http\Server\Request $request, \Amp\Http\Server\Response $response): \Amp\Promise
    {
        return new Success($response);
    }
    /**
     * Handle client.
     *
     * @param Client   $client   Websocket client
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     *
     * @return Promise
     */
    public function handleClient(Client $client, Request $request, Response $response): Promise
    {
        return call([$this, 'handleClientGenerator'], $client);
    }
    /**
     * Handle client.
     *
     * @param Client   $client   Websocket client
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     *
     * @return Promise
     */
    public function handleClientGenerator(Client $client): \Generator
    {
        $socket = yield connect($this->validatorUri);
        try {
            yield [
                call([$this, 'readLoop'], $client, $socket),
                call([$this, 'writeLoop'], $client, $socket),
            ];
        } catch (\Throwable $e) {
            $this->logger->alert("Got exception in loops: $e");
        } finally {
            try {
                $socket->close();
            } catch (\Throwable $e) {
            }
            try {
                $client->close();
            } catch (\Throwable $e) {
            }
        }
    }
    public function readLoop(Client $client, SocketSocket $socket): \Generator
    {
        try {
            $this->logger->warning("Entered read loop");
            while ($message = yield $client->receive()) {
                if (!$message instanceof Message) {
                    throw new \Exception('Connection closed!');
                }
                yield $socket->write(yield $message->buffer());
            }
        } finally {
            $this->logger->warning("Exited read loop");
            $client->close();
            $socket->close();
        }
    }
    public function writeLoop(Client $client, SocketSocket $socket): \Generator
    {
        try {
            $this->logger->warning("Entered write loop");
            while (($message = yield $socket->read()) !== null) {
                yield $client->sendBinary($message);
            }
        } finally {
            $this->logger->warning("Exited write loop");
            $client->close();
            $socket->close();
        }
    }
}



$sockets = [
    Socket\listen('0.0.0.0:80'),
    Socket\listen('[::]:80'),
];

$router = new Router;
$router->addRoute('GET', '/testnet', new Websocket(new TONHandler($ip, $port, $logger)));
$router->addRoute('GET', '/testnetDebug', new Websocket(new TONHandler('127.0.0.1', 9999, $logger)));
$router->setFallback(
    new CallableRequestHandler(
        function () {
            return new Response(Status::FOUND, ['location' => 'https://ton.madelineproto.xyz'], 'Redirecting you to <a href="https://ton.madelineproto.xyz">https://ton.madelineproto.xyz</a>...');
        }
    )
);

$server = new HttpServer($sockets, $router, $logger);

Loop::run(
    function () use ($server) {
        yield $server->start();
    }
);
