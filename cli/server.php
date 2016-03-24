<?php

use Bot2Hook\Logger;
use Bot2Hook\Rabbitmq;
use Bot2Hook\Server;

$config = require __DIR__.'/../app/bootstrap.php';

$logger = new Logger($config['logger']);
$logger->notice('Boot2Hook server starting', $config['server']);

$rabbitmq = new Rabbitmq($config['rabbitmq']);
$server = new Server($config['server'], $rabbitmq, $logger);
$server->launch();

