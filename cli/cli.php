<?php

$task = isset($argv[1]) ? $argv[1] : null;

use Bot2Hook\Consumer\Incoming;
use Bot2Hook\Consumer\Outgoing;
use Bot2Hook\Logger;
use Bot2Hook\Rabbitmq;

$config = require __DIR__.'/../app/bootstrap.php';

$logger = new Logger($config['logger']);
$rabbitmq = new Rabbitmq($config['rabbitmq']);

if (empty($task)) {
    $caller = implode(' ', $argv);
    $logger->err('[CLI] Empty task provided in '.$caller);
    exit(1);
}

try {
    switch ($task) {

        case 'b2h_incoming':
            $body = $argv[2];
            $retry = isset($argv[3]) ? $argv[3] : 0;

            $logger->debug('Boot2Hook consume incoming queue');
            $incoming = new Incoming($config['websocket'], $rabbitmq, $logger);
            $incoming->process($body, $retry);
            break;

        default:
            $logger->err("[CLI] Unknwon task '$task'\n");
            exit(1);
    }
} catch (\Exception $e) {
    $logger->err($e);
    $text_error = sprintf("%s\n%s\n%s: %s\n%s\n%s\n",
        implode(', ', $argv),
        date("Y-m-d H:i:s"),
        "Error {$e->getCode()}",
        $e->getMessage(),
        $e->getFile().':'.$e->getLine(),
        $e->getTraceAsString()
    );
    echo $text_error;
}

