<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli-server'||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){
    http_response_code(403);
    exit;
}
define('RENDORA_PUBLIC_DEMO',true);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/scripts/mysql-demo-connection.php';

HomeAlone\Application::create(new HomeAlone\Database(mysqlDemoConnection()))->run();
