<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$runtimeLock=fopen($root.'/storage/runtime.lock','c');
if(!$runtimeLock||!flock($runtimeLock,LOCK_SH|LOCK_NB)){http_response_code(503);header('Retry-After: 60');exit('Rendora em atualização. Tenta novamente dentro de um minuto.');}
register_shutdown_function(static function()use($runtimeLock){flock($runtimeLock,LOCK_UN);fclose($runtimeLock);});
if(is_file($root.'/storage/deploy.maintenance')){http_response_code(503);exit('Atualização pendente. Consulta o registo de deploy no cPanel.');}
if(!is_file($root.'/.env')){
    if(is_file($root.'/storage/installed.lock')){http_response_code(503);exit('Configuração em falta. Recupera o ficheiro .env no Gestor de Ficheiros do cPanel. A reinstalação está bloqueada.');}
    require $root.'/vendor/autoload.php';
    require_once $root.'/src/Installer.php';
    HomeAlone\Installer::page($root);
    exit;
}
require $root.'/src/bootstrap.php';
HomeAlone\Application::create(database())->run();
