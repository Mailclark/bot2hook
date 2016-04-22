<?php

namespace Bot2Hook;

use Bot2Hook\Entity\Bot;
use Devristo\Phpws\Messaging\WebSocketMessage;
use Devristo\Phpws\Protocol\WebSocketTransportInterface;
use Devristo\Phpws\Server\WebSocketServer;
use React\EventLoop\Factory;

class Server
{
    protected $config = [];

    /** @var Logger */
    protected $logger;

    /** @var WebSocketServer */
    protected $server;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;

        $this->loop = Factory::create();
    }

    public function launch()
    {
        $this->server = new WebSocketServer($this->config['server_url'], $this->loop, $this->logger);

        $this->server->on('message', function (WebSocketTransportInterface $user, WebSocketMessage $message) {
            $data = json_decode($message->getData(), true);
            $this->logger->debug('Bot2hook server receive message ' . $message->getData());
            if (is_array($data) && isset($data['type'])) {
                switch ($data['type']) {
                    case 'add_bot':
                        $this->logger->debug("Bot2hook server, new bot receive via incomming webhook " . json_encode($data['bot']));
                        foreach($this->server->getConnections() as $client) {
                            $client->sendString($message->getData());
                        }
                        break;

                    case 'request_status':
                    case 'request_reporting':
                        foreach($this->server->getConnections() as $client) {
                            $client->sendString($message->getData());
                        }
                        break;

                    case 'request_id':
                        $user->sendString(json_encode([
                            'type' => 'set_id',
                            'batch_id' => '',
                            'batch_count' => '',
                        ]));
                        break;

                    case 'reporting':
                        $user->sendString(json_encode([
                            'memory' => [
                                'usage' => memory_get_usage(true),
                                'peak_usage' => memory_get_peak_usage(true),
                                'limit' => $this->getMemoryLimit(),
                            ],
                            'bots_count' => [
                                'connected' => count($this->bots_connected),
                                'retrying' => count($this->bots_retrying),
                            ],
                        ]));
                        break;
                }
            }
        });

        $this->server->bind();

        $this->loop->run();
    }
}
