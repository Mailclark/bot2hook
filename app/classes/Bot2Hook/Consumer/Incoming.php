<?php

namespace Bot2Hook\Consumer;

use Bot2Hook\Logger;
use Bot2Hook\Rabbitmq;
use Bot2Hook\WebSocketClient;
use Devristo\Phpws\Client\WebSocket;
use React\EventLoop\Factory;

class Incoming extends ConsumerAbstract
{
    public function __construct(array $config, Rabbitmq $rabbitmq, Logger $logger)
    {
        $this->_construct($config, $rabbitmq, $logger);
    }

    public function process($msg, $retry = 0)
    {
        $body = json_decode($msg, true);
        try {
            if (!isset($body['bot'])) {
                throw new \Exception('No bot key in incoming rabbit message');
            }
            $this->addBot($body['bot'], $this->logger);
        } catch (\Exception $e) {
            $this->retry($e, $this->config['rabbit_incoming_queue'], $msg, $retry);
        }
    }

    public function addBot($bot, Logger $logger)
    {
        if (is_array($bot) && !isset($bot['bot_token'])) {
            throw new \Exception('no_bot_token');
        }

        $loop = Factory::create();
        $client = new WebSocketClient($this->config['url_for_server'], $loop, $logger);

        $client->on("error", function () use ($loop, $logger) {
            $logger->err("Add team incoming webhook : can't connect to websocket server");
            $loop->stop();
        });

        $client->on("connect", function () use ($client, $loop, $logger, $bot) {
            $logger->info("Add team incoming webhook : ".json_encode($bot));
            $loop->addTimer(2, function() use ($client, $loop, $bot) {
                $message = [
                    'type' => 'add_bot',
                    'bot' => $bot,
                ];
                $client->send(json_encode($message));
                $loop->stop();
            });
        });

        $client->open();
        $loop->run();
    }
}
