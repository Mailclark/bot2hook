<?php

use Bot2Hook\Consumer;
use Bot2Hook\Logger;
use Bot2Hook\Rabbitmq;

$config = require __DIR__.'/../app/bootstrap.php';

$logger = new Logger($config['logger']);
$logger->notice('Boot2Hook consumer starting');

$rabbitmq = new Rabbitmq($config['rabbitmq']);
$consumer = new Consumer($config['websocket'], $rabbitmq, $config['cmd_php']);
$consumer->launch();
