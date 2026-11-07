<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
if(!defined("RENDORA_PUBLIC_DEMO")) Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
date_default_timezone_set('UTC');
function env(string $key, string $default=''): string {
    if(defined('RENDORA_PUBLIC_DEMO')) return match($key){
        'APP_ENV'=>'demo', 'APP_URL'=>'https://demo.rendora.pt', 'DEMO_MODE', 'COOKIE_SECURE'=>'true', default=>$default,
    };
    // Server process variables must win over .env, including an explicitly empty value.
    // PHP's CLI server populates $_ENV/$_SERVER differently from the CLI executable.
    $processValue=getenv($key);return $processValue!==false?(string)$processValue:(string)($_ENV[$key]??$default);
}
function database(): HomeAlone\Database {
    $mysql=env('DB_MYSQL_DSN');
    if($mysql!==''){
        if(!str_starts_with($mysql,'mysql:'))throw new RuntimeException('DB_MYSQL_DSN inválido.');
        return new HomeAlone\Database(new PDO($mysql,env('DB_MYSQL_USER'),env('DB_MYSQL_PASSWORD'),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]));
    }
    $path=env('DB_PATH','storage/homealone.sqlite');
    if(!str_starts_with($path,'/')&&!preg_match('/^[A-Za-z]:/',$path)&&$path!==':memory:') $path=dirname(__DIR__).'/'.$path;
    return new HomeAlone\Database($path);
}
