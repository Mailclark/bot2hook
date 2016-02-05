<?php

return array(
    //'error_reporting' => E_ALL,
    //'display_errors' => true,
    //'debug' => true,

    //'signature_key' => 'You MUST CHANGE THIS KEY !!',

    /*'logger' => [
        'slack' => [
            'token' => 'a-slack-token',
            'channel' => 'a-slack-channel-id',
        ],
    ],*/

    'rabbitmq' => [
        'host' => 'your_rabbitmq_server',
        'port' => 5672,
        'user' => 'youruser',
        'password' => 'yourpass',
    ],

    'server' => [
        'webhook_url' => '',
        'rabbit_incoming_queue' => 'b2h_incoming_hook',
    ],
);
