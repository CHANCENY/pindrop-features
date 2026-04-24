<?php

namespace Simp\Pindrop\Modules\chat_window\src\WebSocket;

class Connection
{
    protected $socket;
    protected Server $server;
    protected int $id;
    protected array $meta = [];

    public function __construct($socket, Server $server)
    {
        $this->socket = $socket;
        $this->server = $server;
        $this->id = spl_object_id($socket);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSocket()
    {
        return $this->socket;
    }

    public function send(string $message): void
    {
        $encoded = $this->server->encode($message);
        @socket_write($this->socket, $encoded);
    }

    public function close(): void
    {
        socket_close($this->socket);
    }

    // metadata helpers
    public function set(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->meta;
    }

    public function getConnectionStatus(): bool
    {
        return !empty(socket_read($this->socket, 1024));
    }
}