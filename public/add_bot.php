<?php

use Bot2Hook\Consumer\Incoming;
use Bot2Hook\Signature;
use Bot2Hook\Logger;

$config = require __DIR__.'/../app/bootstrap.php';

$logger = new Logger($config['logger']);
$signature = new Signature($config['signature_key']);

$uri = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'];
if ($_SERVER['SERVER_PORT'] != '80') {
    $uri .= ':'.$_SERVER['SERVER_PORT'];
}
$uri .= $_SERVER['SCRIPT_NAME'];
try {
    if (empty($_SERVER['HTTP_X_BOT2HOOK_SIGNATURE']) ||
        !$signature->isValid($_SERVER['HTTP_X_BOT2HOOK_SIGNATURE'], $uri, $_GET)) {
        throw new \Exception('not_valid_signature');
    }
    if (!isset($_GET['bot'])) {
        throw new \Exception('No bot key in incoming web hook');
    }

    $team = json_decode($_GET['bot'], true);
    Incoming::addTeam($team, $logger);
} catch (\Exception $e) {
    $this->logger->err("Incoming webhook error: ".$e->getMessage()."\n".json_encode($_GET)."\n");
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
