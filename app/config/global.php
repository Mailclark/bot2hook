<?php

use Zend\Log\Logger;

return array(
    'error_reporting' => E_ALL & ~E_NOTICE,
    'display_errors' => false,
    'debug' => false,

    'cmd_php' => 'php',

    'logger' => [
        'register_error_handler' => false,
        'register_exception_handler' => false,
        'stream' => [
            'priority' => Logger::DEBUG,
            'uri' => DIR_LOGS.'/error.log',
        ],
    ],

    'rabbitmq' => [
        'host' => 'bot2hook_rabbitmq',
        'port' => 5672,
        'user' => 'slack',
        'password' => 'bot',
    ],

    'websocket' => [
        'url_for_server' => 'tcp://0.0.0.0:12345',
        'url_for_client' => 'ws://0.0.0.0:12345/',
        'sqlite_path' => DIR_STORAGE.'/sqlite/bot2hook.db',
        'delay_ping' => 10,
        'concurrent_connecting_count' => 2,
        'rabbit_outgoing_queue' => 'b2h_outgoing',
        'rabbit_incoming_queue' => 'b2h_add_bot',
        'events_excluded' => 'pong,reconnect_url,presence_change,hello',
        'batch_count_total' => 1,
        'batch_count_active' => 1,
    ],
);
