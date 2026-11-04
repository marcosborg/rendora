<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
date_default_timezone_set('UTC');
function env(string $key, string $default=''): string {
    // Server process variables must win over .env, including an explicitly empty value.
    // PHP's CLI server populates $_ENV/$_SERVER differently from the CLI executable.
    $processValue=getenv($key);return $processValue!==false?(string)$processValue:(string)($_ENV[$key]??$default);
}
function database(): HomeAlone\Database {
    $path=env('DB_PATH','storage/homealone.sqlite');
    if(!str_starts_with($path,'/')&&!preg_match('/^[A-Za-z]:/',$path)&&$path!==':memory:') $path=dirname(__DIR__).'/'.$path;
    return new HomeAlone\Database($path);
}
