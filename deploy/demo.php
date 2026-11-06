<?php
declare(strict_types=1);
define('RENDORA_PUBLIC_DEMO', true);
$root=dirname(__DIR__).'/rendora-demo';
// A new generation becomes active only after its upload is complete and renamed.
$generations=glob($root.'/storage/demo-*.sqlite');
$path=$generations ? end($generations) : false;
if(!$path||!preg_match('/^demo-[0-9]{14}\.sqlite$/D',basename($path))||!is_file($path)||is_link($path)){
    http_response_code(503);
    exit('Demonstração em preparação.');
}
require $root.'/src/bootstrap.php';
$db=new HomeAlone\Database($path);
if((int)$db->one('PRAGMA application_id')['application_id']!==1380205636){
    http_response_code(503);
    exit('Base de demonstração inválida.');
}
HomeAlone\Application::create($db)->run();
