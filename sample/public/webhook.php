<?php

use Bot2Hook\Logger;

$config = require __DIR__.'/../../app/bootstrap.php';

if (!$config['debug']) {
    exit('Only in debug');
}

header('Cache-Control: no-cache, must-revalidate');
header('Content-type: application/json');

$logger = new Logger($config['logger']);
$signature = new \Bot2Hook\Signature($config['signature_key']);

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
    if (!isset($request['webhook_event'])) {
        throw new \Exception('No webhook_event in incoming web hook');
    }

    $logger->debug("Sample webhook event received: \n".json_encode(json_decode($request['webhook_event']), JSON_PRETTY_PRINT));

    echo json_encode([
        'ok' => true,
    ]);
} catch (\Exception $e) {
    $logger->err("Sample webhook error: ".$e->getMessage()."\n".json_encode($request)."\n");
    $logger->err("Sample webhook error trace: ".$e->getTraceAsString());
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}




