<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/database/seed.php';

$path=$argv[1]??'';
if(PHP_SAPI!=='cli'||!preg_match('/^demo-[0-9]{14}\.sqlite$/D',basename($path))){
    fwrite(STDERR,"Indique um novo ficheiro demo-AAAAMMDDHHMMSS.sqlite.\n");
    exit(1);
}
umask(0077);
$file=@fopen($path,'x');
if(!$file){
    fwrite(STDERR,"Não foi possível criar um ficheiro novo.\n");
    exit(1);
}
fclose($file);
try {
    $db=new HomeAlone\Database($path);
    $db->migrate();
    seed($db, platformAdmin: false);
    $db->pdo->exec('PRAGMA application_id=1380205636');
    if($db->one('PRAGMA integrity_check')['integrity_check']!=='ok'||$db->all('PRAGMA foreign_key_check')){
        throw new RuntimeException('Base inválida.');
    }
    echo 'SQLite '.$db->one('SELECT sqlite_version() version')['version']." · base fictícia preparada.\n";
} catch(Throwable $e) {
    unset($db);
    unlink($path);
    fwrite(STDERR,$e->getMessage()."\n");
    exit(1);
}
