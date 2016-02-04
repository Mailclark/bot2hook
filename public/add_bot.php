<?php

use Bot2Hook\Consumer\Incoming;
use Bot2Hook\Signature;
use Bot2Hook\Logger;

$config = require __DIR__.'/../app/bootstrap.php';

header('Cache-Control: no-cache, must-revalidate');
header('Content-type: application/json');

$logger = new Logger($config['logger']);
$signature = new Signature($config['signature_key']);

$uri = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'];
if ($_SERVER['SERVER_PORT'] != '80') {
    $uri .= ':'.$_SERVER['SERVER_PORT'];
}
$uri .= $_SERVER['SCRIPT_NAME'];
$b2h_signature = empty($_SERVER['HTTP_X_BOT2HOOK_SIGNATURE']) ? '' : $_SERVER['HTTP_X_BOT2HOOK_SIGNATURE'];
$request = $_SERVER['REQUEST_METHOD'] == 'GET' ? $_GET : $_POST;
try {
    if (!$signature->isValid($b2h_signature, $uri, $request)) {
        throw new \Exception('not_valid_signature');
    }
    if (!isset($request['bot'])) {
        throw new \Exception('No bot key in incoming web hook');
    }

    $bot = json_decode($request['bot'], true);
    if (empty($bot)) {
        $bot = $request['bot'];
    }
    Incoming::addBot($bot, $logger);

    echo json_encode([
        'ok' => true,
    ]);
} catch (\Exception $e) {
    $logger->err("Incoming webhook error: ".$e->getMessage()."\n".json_encode($request)."\n");
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
