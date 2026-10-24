<?php

/**
 * Router for PHP's built-in web server (local development only):
 * php -S 127.0.0.1:8077 server.php
 */
$publicPath = __DIR__.'/public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
