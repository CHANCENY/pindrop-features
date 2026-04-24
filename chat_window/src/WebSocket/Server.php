<?php

namespace Simp\Pindrop\Modules\chat_window\src\WebSocket;

use Throwable;

class Server
{
    protected string $host;
    protected int $port;

    protected $master;

    protected array $clients = [];
    protected array $connectionMap = [];
    protected array $handlers = [];

    public function __construct(string $host = "0.0.0.0", int $port = 8000)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Register an event listener.
     */
    public function on(string $event, callable $callback): void
    {
        $this->handlers[$event][] = $callback;
    }

    /**
     * Emit an event to all registered listeners.
     */
    protected function emit(string $event, ...$args): void
    {
        if (!isset($this->handlers[$event])) {
            return;
        }

        foreach ($this->handlers[$event] as $handler) {
            $handler(...$args);
        }
    }

    /**
     * Start the WebSocket server loop.
     */
    public function run(): void
    {
        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);

        socket_bind($this->master, $this->host, $this->port);
        socket_listen($this->master);

        echo "WebSocket running on {$this->host}:{$this->port}\n";

        $this->clients[] = $this->master;

        while (true) {
            try{
                $changed = $this->clients;

                $write = null;
                $except = null;

                socket_select($changed, $write, $except, null);

                foreach ($changed as $socket) {

                    if ($socket === $this->master) {
                        $this->handleNewConnection($socket);
                        continue;
                    }

                    $this->handleClientMessage($socket);
                }
            }catch (Throwable $exception){
                foreach ($this->clients as $key=>$client) {
                    if (empty(socket_read($client, 8192))){
                        echo "Remote has drop the connection\n";
                        unset($this->clients[$key]);
                    }
                }
            }
        }
    }

    /**
     * Handle a new incoming client connection.
     */
    protected function handleNewConnection($socket): void
    {
        $client = socket_accept($this->master);
        $this->clients[] = $client;

        $headers = socket_read($client, 1024);
        $this->handshake($client, $headers);

        $conn = $this->getConnection($client);

        $this->emit('connection', $conn);
    }

    /**
     * Handle incoming client message or disconnect.
     */
    protected function handleClientMessage($socket): void
    {
        $buffer = '';
        $bytes = @socket_recv($socket, $buffer, 2048, 0);

        if ($bytes <= 0) {
            $conn = $this->getConnection($socket);

            $this->emit('close', $conn);
            $this->disconnect($socket);

            return;
        }

        if (!isset($buffer[1])) {
            return;
        }

        $decoded = $this->decode($buffer);

        if ($decoded === null || $decoded === '') {
            return;
        }

        $conn = $this->getConnection($socket);

        $this->emit('message', $conn, $decoded);
    }

    /**
     * Get or create a Connection object for a socket.
     */
    protected function getConnection($socket): Connection
    {
        $id = spl_object_id($socket);

        if (!isset($this->connectionMap[$id])) {
            $this->connectionMap[$id] = new Connection($socket, $this);
        }

        return $this->connectionMap[$id];
    }

    /**
     * Remove and close a socket connection.
     */
    protected function disconnect($socket): void
    {
        $id = spl_object_id($socket);

        unset($this->connectionMap[$id]);

        $index = array_search($socket, $this->clients, true);

        socket_close($socket);

        if ($index !== false) {
            unset($this->clients[$index]);
        }
    }

    /**
     * Perform WebSocket handshake.
     */
    protected function handshake($client, string $headers): void
    {
        if (!preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $headers, $matches)) {
            return;
        }

        $key = trim($matches[1]);

        $accept = base64_encode(
            pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'))
        );

        $response =
            "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: $accept\r\n\r\n";

        socket_write($client, $response);
    }

    /**
     * Decode a WebSocket frame.
     */
    public function decode(string $data): ?string
    {
        if (strlen($data) < 2) {
            return null;
        }

        $length = ord($data[1]) & 127;

        if ($length === 126) {
            $mask = substr($data, 4, 4);
            $payload = substr($data, 8);
        } elseif ($length === 127) {
            $mask = substr($data, 10, 4);
            $payload = substr($data, 14);
        } else {
            $mask = substr($data, 2, 4);
            $payload = substr($data, 6);
        }

        if (!$mask) {
            return null;
        }

        $text = '';

        for ($i = 0; $i < strlen($payload); $i++) {
            $text .= $payload[$i] ^ $mask[$i % 4];
        }

        return $text;
    }

    /**
     * Encode a message into WebSocket frame.
     */
    public function encode(string $text): string
    {
        $b1 = 0x81;
        $length = strlen($text);

        if ($length <= 125) {
            return pack('CC', $b1, $length) . $text;
        }

        if ($length <= 65535) {
            return pack('CCn', $b1, 126, $length) . $text;
        }

        return pack('CCNN', $b1, 127, 0, $length) . $text;
    }
}