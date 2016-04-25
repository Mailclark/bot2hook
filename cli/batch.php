<?php

use Bot2Hook\Batch;
use Bot2Hook\Logger;
use Bot2Hook\Rabbitmq;

$config = require __DIR__.'/../app/bootstrap.php';

$logger = new Logger($config['logger']);
$logger->notice('Boot2Hook batch starting', $config['websocket']);

$rabbitmq = new Rabbitmq($config['rabbitmq']);
$batch = new Batch($config['websocket'], $rabbitmq, $logger);
$batch->launch();

