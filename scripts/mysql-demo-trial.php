<?php
declare(strict_types=1);

// An offline rehearsal. Apply the trial schema to a disposable database first.
try {
    $path=$argv[1]??'';
    $presentation=($argv[2]??'')==='--presentation-rehearsal';
    if(PHP_SAPI!=='cli'||!is_file($path))throw new RuntimeException('Indique uma cópia local da base de origem.');
    $source=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $source->exec('PRAGMA query_only=ON');
    $isDemo=(int)$source->query('PRAGMA application_id')->fetchColumn()===1380205636;
    $hasAdmin=(bool)$source->query('SELECT 1 FROM users WHERE platform_admin<>0 LIMIT 1')->fetch();
    if($source->query('PRAGMA integrity_check')->fetchColumn()!=='ok'
        ||$source->query('PRAGMA foreign_key_check')->fetch()
        ||($presentation?($isDemo||!$hasAdmin):(!$isDemo||$hasAdmin))){
        throw new RuntimeException('A origem não é uma base íntegra do ambiente indicado.');
    }

    require __DIR__.'/mysql-demo-connection.php';
    if($presentation&&!preg_match('/^mysql:unix_socket=[^;]+;dbname=rendora_trial(?:;charset=utf8mb4)?$/D',getenv('RENDORA_TRIAL_MYSQL_DSN')?:'')){
        throw new RuntimeException('O ensaio da apresentação exige um socket MySQL local.');
    }
    $target=mysqlDemoConnection();
    $target->exec("SET time_zone='+00:00'");

    $tables=$source->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY rowid")->fetchAll(PDO::FETCH_COLUMN);
    $excluded=['sessions','reset_tokens','rate_limits'];
    $target->beginTransaction();
    try {
        foreach($tables as $table){
            if($table==='migrations')continue;
            if(!preg_match('/^[a-z_]+$/D',$table))throw new RuntimeException('Nome de tabela inesperado.');
            if($target->query("SELECT 1 FROM `$table` LIMIT 1")->fetchColumn())throw new RuntimeException('O destino já contém dados.');
            if(in_array($table,$excluded,true))continue;

            $insert=null;
            $count=0;
            foreach($source->query("SELECT * FROM `$table` ORDER BY rowid") as $row){
                if($insert===null){
                    $columns=array_keys($row);
                    foreach($columns as $column){
                        if(!preg_match('/^[a-z_]+$/D',$column))throw new RuntimeException('Nome de coluna inesperado.');
                    }
                    $fields='`'.implode('`,`',$columns).'`';
                    $values=implode(',',array_fill(0,count($columns),'?'));
                    $insert=$target->prepare("INSERT INTO `$table` ($fields) VALUES ($values)");
                }
                $insert->execute(array_values($row));
                $count++;
            }
            if((int)$target->query("SELECT COUNT(*) FROM `$table`")->fetchColumn()!==$count){
                throw new RuntimeException('Contagem divergente em '.$table.'.');
            }
        }
        foreach(['charges','payments'] as $table){
            $query="SELECT company_id, SUM(amount_cents) total FROM $table GROUP BY company_id ORDER BY company_id";
            $totals=static fn(PDO $db)=>array_map('strval',$db->query($query)->fetchAll(PDO::FETCH_KEY_PAIR));
            if($totals($source)!==$totals($target))throw new RuntimeException('Totais divergentes em '.$table.'.');
        }
        $target->commit();
    } catch(Throwable $e) {
        $target->rollBack();
        throw $e;
    }
    echo "Importação de ensaio validada. Contagens e totais financeiros coincidem.\n";
} catch(Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n");
    exit(1);
}
