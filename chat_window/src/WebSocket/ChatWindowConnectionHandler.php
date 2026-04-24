<?php

namespace Simp\Pindrop\Modules\chat_window\src\WebSocket;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Modules\chat_window\src\Chat\Agent;
use Simp\Pindrop\Modules\chat_window\src\Chat\ChatItem;
use Simp\Pindrop\Modules\chat_window\src\Chat\ChatItemContent;

class ChatWindowConnectionHandler
{
    protected $connections;
    protected \CLIPrinter $printer;
    protected ChatItem $chatItem;
    protected ChatItemContent $chatItemContent;
    protected Agent $agent;
    public function __construct()
    {
        $this->connections = new \SplObjectStorage;
        $this->printer = new \CLIPrinter();
        $this->chatItem = \getAppContainer()->get('chat.item.manager');
        $this->chatItemContent = \getAppContainer()->get('chat.item.content.manager');
        $this->agent = \getAppContainer()->get('chat.agent');
    }

    public function onConnection(Connection $conn): void
    {
        $message = "Welcome to our support center. please wait for moment while our agent preparing to attend you.";
        $this->connections->attach($conn);
        $conn->send(json_encode([
            'message' => $message,
        ]));
    }

    /**
     * @throws DatabaseException
     */
    public function onMessage($conn, $message): void {

        try{

            if (!is_array($message)) {
                $message = json_decode($message, true);
            }

            if (empty($message['sessionId'])) {
                $conn->send(json_encode([
                    'message' => "Sorry something went wrong. Please try again later.",
                ]));
                return;
            }

            // find the connection if exist
            $alreadyConnection = null;
            foreach ($this->connections as $connection) {
                if ($connection === $conn) {
                    $alreadyConnection = $connection;
                    break;
                }

                $profile = $connection->get('profile');
                if ($profile['sessionId'] === $message['sessionId']) {
                    $alreadyConnection = $connection;
                    break;
                }
            }


            // save the message
            $messageId = 0;
            if (!empty($message['message']) && !empty($message['type'])
                && $message['type'] !== 'init'
                && $message['type'] !== 'agent_init'
                && $message['type'] !== 'command'
                && $alreadyConnection
            ) {
                $profile = $alreadyConnection->get('profile');
                $savableData = [
                    'message_type' => $message['type'],
                    'content'      => $message['message'],
                ];

                $messageId = $this->chatItemContent->addContent($profile['ticket'] ?? $message['id'], $savableData);
            }


            /**@var Connection $connection **/
            foreach ($this->connections as $connection) {

                if ($connection === $conn) {

                    if ($message['type'] === 'init') {

                        $result = $this->chatItem->createChatItem($message['sessionId']);
                        if ($result) {
                            $meta = [
                                'sessionId' => $message['sessionId'],
                                'user'      => $message['user'],
                                'ticket'    => $result,
                            ];
                            $connection->set('profile', $meta);
                        }
                        $data = [
                            'ticket' => $result,
                            'message' => "Session created for your with ticket ID: {$result}, please proceed with how can we help you?",
                        ];
                        $conn->send(json_encode($data));
                    }

                    if ($message['type'] === 'agent_init') {
                        $agent = $message['agent'];
                        $this->agent->updateStatus((int) $agent['id'], 'active');
                        $data = [
                            'message' => "Welcome {$agent['first_name']} to our support team.",
                            'agent'    => $agent,
                        ];
                        $conn->send(json_encode($data));
                        $meta = [
                            'sessionId' => $message['sessionId'],
                            'agent'      => $agent,
                            'support'    => true,
                        ];
                        $connection->set('profile', $meta);
                    }

                }

                if (!empty($message['action'])) {

                    if ($message['action'] === 'close') {
                        $profile = $connection->get('profile');
                        if (!empty($profile['sessionId']) && $profile['sessionId'] === $message['sessionId']) {
                            $connection->send(json_encode([
                                'message' => "Session closed for your support team.",
                                'sessionId' => $message['sessionId'],
                            ]));
                            $this->chatItem->updateChatItem($message['id'], 'closed');
                            $connection->close();
                            $this->connections->detach($connection);
                            return;
                        }
                    }

                    if ($message['action'] === 'resolved') {
                        $profile = $connection->get('profile');
                        if (!empty($profile['sessionId']) && $profile['sessionId'] === $message['sessionId']) {
                            $connection->send(json_encode([
                                'message' => "Your ticket is resolved for your support team.",
                                'sessionId' => $message['sessionId'],
                            ]));
                            $this->chatItem->updateChatItem($message['id'], 'resolved');
                            return;
                        }
                    }

                    if ($message['action'] === 'assigned') {
                        $profile = $connection->get('profile');
                        if (!empty($profile['sessionId']) && $profile['sessionId'] === $message['sessionId']) {
                            $connection->send(json_encode([
                                'message' => $message['message'],
                                'sessionId' => $message['sessionId'],
                            ]));
                            $this->chatItem->assignSupportMember($message['id'], (int) $message['agentId']);
                            $profile['ticket'] = $message['id'];
                            $connection->set('profile', $profile);
                            return;
                        }
                    }

                    if ($message['action'] === 'conversations') {
                        $tickets = $this->chatItem->getChatItems();
                        $conn->send(json_encode([
                            'key' => 'conversations',
                            'tickets' => $tickets,
                        ]));
                        return;
                    }

                }

                if ($message['type'] !== 'init' && $message['type'] !== 'agent_init') {

                    // Get object of message
                    $messageObject = [];
                    $messageObject['content'] = $this->chatItemContent->getContent($messageId);
                    $messageObject['meta'] = $connection->get('profile');
                    $messageObject['key']  = 'normal';

                    // here send message to all connections
                    $profile = $connection->get('profile');
                    if (!empty($profile['support'])) {
                        $connection->send(json_encode($messageObject));
                    }

                    if ($message['type'] === 'support' && !empty($profile['sessionId']) && $profile['sessionId'] == $message['sessionId']) {
                        $msg = [
                            'sessionId' => $messageObject['content']['cid'],
                            'message'   => $messageObject['content']['content'],
                        ];
                        $connection->send(json_encode($msg));
                    }
                }
            }
        }
        catch (\Throwable $exception){
            $this->printer->printLine($exception->getTraceAsString(). PHP_EOL. $exception->getMessage());
            $conn->send(json_encode([
                'message' => "Sorry we couldn't process your request. Try again later.",
            ]));
        };

    }

    public function onDisconnection(Connection $conn): void
    {
        $this->connections->detach($conn);
    }
}