<?php

if (!defined('APP_ENV') && !empty($_SERVER['APP_ENV'])) {
    define('APP_ENV', $_SERVER['APP_ENV']);
} else {
    define('APP_ENV', 'prod');
}

require_once __DIR__.'/../vendor/autoload.php';

!defined('DIR_CONFIG')  && define('DIR_CONFIG', __DIR__.'/config');
!defined('DIR_STORAGE')  && define('DIR_STORAGE', __DIR__.'/../storage');
!defined('DIR_LOGS')  && define('DIR_LOGS', __DIR__.'/../storage/logs');
!defined('DB_FILE')  && define('DB_FILE', __DIR__.'/../db/bot2hook.sql');

$config = require DIR_CONFIG."/global.php";
if (defined('APP_ENV')) {
    $config = array_replace_recursive($config, require DIR_CONFIG."/env/".APP_ENV.".php");
}

// set the error handling
error_reporting($config['error_reporting']);
ini_set('display_errors', $config['display_errors']);

return $config;
