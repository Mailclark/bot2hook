<?php

if (!defined('CONF_FILE') && !empty($_SERVER['CONF_FILE'])) {
    define('CONF_FILE', $_SERVER['CONF_FILE']);
} else {
    exit('no CONF_FILE');
}

require_once __DIR__.'/../vendor/autoload.php';

!defined('DIR_CONFIG')  && define('DIR_CONFIG', __DIR__.'/config');
!defined('DIR_STORAGE')  && define('DIR_STORAGE', __DIR__.'/../storage');
!defined('DIR_LOGS')  && define('DIR_LOGS', __DIR__.'/../storage/logs');
!defined('DB_FILE')  && define('DB_FILE', __DIR__.'/../db/bot2hook.sql');

$config = require DIR_CONFIG."/global.php";
$config = array_replace_recursive($config, require DIR_CONFIG."/env/".CONF_FILE.".php");

// set the error handling
error_reporting($config['error_reporting']);
ini_set('display_errors', $config['display_errors']);

return $config;
