<?php
declare(strict_types=1);

function mysqlDemoConnection(): PDO {
    $dsn=getenv('RENDORA_TRIAL_MYSQL_DSN')?:'';
    if(!str_starts_with($dsn,'mysql:')||!preg_match('/(?:^mysql:|;)dbname=rendora_trial(?:;|$)/',$dsn)){
        throw new RuntimeException('Indique um destino MySQL com dbname=rendora_trial.');
    }
    $pdo=new PDO($dsn,getenv('RENDORA_TRIAL_MYSQL_USER')?:'',getenv('RENDORA_TRIAL_MYSQL_PASSWORD')?:'',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='rendora_trial'){
        throw new RuntimeException('O destino selecionado não é rendora_trial.');
    }
    return $pdo;
}
