<?php

use Bot2Hook\Logger;

$config = require __DIR__.'/../app/bootstrap.php';

if (!$config['debug']) {
    exit('Only in debug');
}

$logger = new Logger($config['logger']);
$signature = new \Bot2Hook\Signature($config['signature_key']);

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
    if (!isset($_GET['webhook_event'])) {
        throw new \Exception('No webhook_event in incoming web hook');
    }

    $logger->debug($_GET['webhook_event']);

    echo json_encode([
        'ok' => true,
    ]);
} catch (\Exception $e) {
    $this->logger->err("Sample webhook error: ".$e->getMessage()."\n".json_encode($_GET)."\n");
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}




