<?php

use Simp\Pindrop\Modules\chat_window\src\WebSocket\ChatWindowConnectionHandler;
use Simp\Pindrop\Modules\chat_window\src\WebSocket\Server;

return [
    'chat:server' => 'startServer',
];

function startServer(CLIPrinter $printer, ...$values): void
{

    // Start server
    $socketHost = $_ENV['SOCKET_HOST'] ?? NULL;
    $socketPort = $_ENV['SOCKET_PORT'] ?? NULL;
    $socketActivated = $_ENV['SOCKET_ACTIVATED'] ?? FALSE;

    if (!empty($socketActivated) && !empty($socketHost) && !empty($socketPort)) {

        $chatWindowConnectionHandler = new ChatWindowConnectionHandler();
        $server = new Server($socketHost, $socketPort);

        $server->on('connection', function ($conn) use ($printer, $chatWindowConnectionHandler) {
            $printer->printLine ("Client {$conn->getId()} connected", GREEN);
            $chatWindowConnectionHandler->onConnection($conn);
        });

        $server->on('message', function ($conn, $msg) use ($printer, $chatWindowConnectionHandler) {
            $chatWindowConnectionHandler->onMessage($conn, $msg);
        });

        $server->on('close', function ($conn) use ($printer, $chatWindowConnectionHandler) {
            $printer->printLine("Client {$conn->getId()} disconnected\n", RED);
            $chatWindowConnectionHandler->onDisconnection($conn);

        });

        $server->run();
    }
    else {
        $printer->printLine("socket server not activated", RED);
    }
}