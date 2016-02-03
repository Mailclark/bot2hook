<?php

$task = isset($argv[1]) ? $argv[1] : null;

if (empty($task)) {
    $caller = implode(' ', $argv);
    error_log("[CLI] Empty task provided in '$caller'\n", 3, DIR_LOGS.'/error-cli.log');
    exit(1);
}

use Bot2Hook\Consumer\Incoming;
use Bot2Hook\Consumer\Outgoing;
use Bot2Hook\Logger;
use Bot2Hook\Rabbitmq;

$config = require __DIR__.'/../app/bootstrap.php';

$logger = new Logger($config['logger']);
$logger->notice('Boot2Hook consumer starting');

$rabbitmq = new Rabbitmq($config['rabbitmq']);

try {
    switch ($task) {

        case 'b2h_outgoing':
            $body = $argv[2];
            $retry = isset($argv[3]) ? $argv[3] : 0;

            $outgoing = new Outgoing($config['server'], $rabbitmq, $logger, $config['signature_key']);
            $outgoing->process($body, $retry);
            break;

        case 'b2h_incoming':
            $body = $argv[2];
            $retry = isset($argv[3]) ? $argv[3] : 0;

            $incoming = new Incoming($config['server'], $rabbitmq, $logger);
            $incoming->process($body, $retry);
            break;

        default:
            error_log("[CLI] Unknwon task '$task'\n", 3, DIR_LOGS.'/error-cli.log');
            exit(1);
    }
} catch (\Exception $e) {
    $message = date("Y-m-d H:i:s").' - Cli '.__FILE__.' PHPException: '.$e->getCode().'-'.$e->getMessage()."\n".$e->getTraceAsString();
    error_log($message."\n", 3, DIR_LOGS.'/error-cli.log');
    $app->logger->addAlert($message);
    echo $message;
}

