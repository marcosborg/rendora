<?php
declare(strict_types=1);
/** Deliberately does not invoke npm, Composer, shell commands or fetch dependencies. */
function rendoraDeploy(string $repository,string $accountHome,bool $activateMysql=false): array {
    if(PHP_VERSION_ID<80200)throw new RuntimeException('PHP 8.2 ou superior necessário.');
    foreach(['pdo_sqlite','mbstring','curl','fileinfo','iconv','openssl','zlib'] as $extension)if(!extension_loaded($extension))throw new RuntimeException('Extensão PHP em falta: '.$extension);
    $repository=realpath($repository)?:throw new RuntimeException('Repositório não encontrado.');
    $home=realpath($accountHome)?:throw new RuntimeException('Pasta da conta não encontrada.');
    $configFile=$home.'/.rendora-deploy.json';
    $config=is_file($configFile)?json_decode(file_get_contents($configFile),true,512,JSON_THROW_ON_ERROR):[];
    $resolve=function(string $relative)use($home):string {
        $relative=str_replace('\\','/',$relative);
        if(!preg_match('~^[a-zA-Z0-9_./-]+$~',$relative)||str_starts_with($relative,'/')||preg_match('~(^|/)\.\.?(/|$)~',$relative))throw new RuntimeException('O destino deve ser um caminho relativo seguro dentro da conta.');
        $path=$home;
        foreach(explode('/',trim($relative,'/')) as $part){if($part==='')continue;$path.='/'.$part;if(is_link($path))throw new RuntimeException('Destinos com ligações simbólicas não são permitidos.');}
        return $path;
    };
    $private=$resolve($config['private_path']??'rendora');$public=$resolve($config['public_path']??'public_html/rendora');
    $contains=fn($a,$b)=>$a===$b||str_starts_with(str_replace('\\','/',$b).'/',str_replace('\\','/',$a).'/');
    if($contains($private,$public)||$contains($public,$private)||$contains($repository,$private)||$contains($private,$repository)||$contains($repository,$public)||$contains($public,$repository)||$contains($home.'/public_html',$private))throw new RuntimeException('Código privado, repositório e pasta pública devem estar separados.');
    foreach([$private,$public] as $directory){
        if(is_dir($directory)&&count(array_diff(scandir($directory),['.','..']))&&!is_file($directory.'/.rendora-deployment.json'))throw new RuntimeException('Destino já contém ficheiros não geridos pela Rendora: '.$directory);
        if(is_file($directory.'/.rendora-deployment.json')){
            $marker=json_decode(file_get_contents($directory.'/.rendora-deployment.json'),true);
            if(($marker['private']??null)!==$private||($marker['public']??null)!==$public)throw new RuntimeException('O destino pertence a outra instalação.');
        }
    }
    foreach(['vendor/autoload.php','public/app.html','public/assets','public/brand','deploy/web.php','src/Installer.php'] as $file)if(!file_exists($repository.'/'.$file))throw new RuntimeException('Entrega incompleta: '.$file);
    $manifest=json_decode(file_get_contents($repository.'/deploy/artifact-manifest.json'),true,512,JSON_THROW_ON_ERROR);
    if(empty($manifest['files']))throw new RuntimeException('Manifesto da entrega vazio.');
    foreach($manifest['files'] as $file=>$hash){
        if(str_contains($file,'..')||!is_file($repository.'/'.$file))throw new RuntimeException('Entrega incompleta: '.$file);
        $bytes=file_get_contents($repository.'/'.$file);if(!str_contains($bytes,"\0"))$bytes=str_replace("\r\n","\n",$bytes);
        if(!hash_equals($hash,hash('sha256',$bytes)))throw new RuntimeException('Entrega alterada: '.$file.'. Prepara e envia novamente os ficheiros.');
    }
    // Check the actual bundled dependency platform requirements before any mutation.
    require_once $repository.'/vendor/autoload.php';
    $copy=function(string $from,string $to)use(&$copy):void {
        if(is_link($from)||is_link($to))throw new RuntimeException('Ligação simbólica inesperada.');
        if(is_dir($from)){if(!is_dir($to)&&!mkdir($to,0755,true))throw new RuntimeException('Não foi possível criar pasta.');foreach(array_diff(scandir($from),['.','..']) as $name)$copy($from.'/'.$name,$to.'/'.$name);return;}
        $tmp=$to.'.deploy-'.bin2hex(random_bytes(6));if(!copy($from,$tmp)||!rename($tmp,$to))throw new RuntimeException('Falha ao copiar ficheiros.');
    };
    foreach([$private,$public,$private.'/storage',$private.'/storage/backups'] as $dir)if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Sem permissões para criar o destino.');
    chmod($public,0755);
    foreach([$private,$public] as $dir)file_put_contents($dir.'/.rendora-deployment.json',json_encode(['private'=>$private,'public'=>$public],JSON_THROW_ON_ERROR));
    $lock=fopen($private.'/storage/runtime.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('A aplicação está ocupada. Repete Deploy HEAD Commit.');
    $backup=null;
    $mysql=null;
    $mysqlConfig=null;
    $pendingEnv=null;
    try {
        // Existing config is read only. Runtime files are never copied from Git.
        if(is_file($private.'/.env')){
            $settings=Dotenv\Dotenv::parse(file_get_contents($private.'/.env'));
            $mysqlConfig=$settings;
            if($activateMysql&&empty($settings['DB_MYSQL_DSN'])){
                $candidate=$private.'/storage/mysql-presentation.env';
                if(!is_file($candidate)||is_link($candidate)||(fileperms($candidate)&0077)!==0){
                    throw new RuntimeException('Configuração MySQL privada indisponível.');
                }
                $mysqlConfig=Dotenv\Dotenv::parse(file_get_contents($candidate));
                if(($mysqlConfig['DB_MYSQL_DSN']??'')!=='mysql:host=localhost;dbname=rendora_presentation;charset=utf8mb4'){
                    throw new RuntimeException('Destino MySQL de apresentação inesperado.');
                }
                $pendingEnv=file_get_contents($private.'/.env')."\n".file_get_contents($candidate);
            }
            if(!empty($mysqlConfig['DB_MYSQL_DSN'])){
                $mysql=new PDO($mysqlConfig['DB_MYSQL_DSN'],$mysqlConfig['DB_MYSQL_USER'],$mysqlConfig['DB_MYSQL_PASSWORD'],[
                    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES=>false,
                ]);
                if((int)$mysql->query('SELECT COUNT(*) FROM trial_writer_lock WHERE id=1')->fetchColumn()!==1){
                    throw new RuntimeException('Esquema MySQL incompleto.');
                }
            }
            $dbPath=$settings['DB_PATH']??'storage/homealone.sqlite';
            if(!str_starts_with($dbPath,'/')&&!preg_match('/^[A-Za-z]:/',$dbPath))$dbPath=$private.'/'.$dbPath;
            if($pendingEnv!==null&&!is_file($dbPath))throw new RuntimeException('Base SQLite de origem em falta.');
            if((!$mysql||$pendingEnv!==null)&&is_file($dbPath)){
                $pdo=new PDO('sqlite:'.$dbPath);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
                $backup=$private.'/storage/backups/pre-deploy-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
                // Reserve the writer lock while copying the rollback-mode database.
                // A WAL database requires a different backup strategy.
                $pdo->exec('PRAGMA busy_timeout=10000');
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    if(strtolower($pdo->query('PRAGMA journal_mode')->fetchColumn())==='wal'){
                        throw new RuntimeException('O backup pré-deploy exige uma base SQLite sem WAL.');
                    }
                    $copy($dbPath,$backup);
                    chmod($backup,0600);
                    $snapshot=new PDO('sqlite:'.$backup);
                    if($snapshot->query('PRAGMA integrity_check')->fetchColumn()!=='ok'){
                        throw new RuntimeException('A cópia SQLite falhou a verificação de integridade.');
                    }
                    if($pendingEnv!==null)rendoraVerifyMysqlCopy($snapshot,$mysql);
                    $snapshot=null;
                } finally {
                    $pdo->exec('ROLLBACK');
                }
                $pdo=null;
            }
        }
        if($activateMysql&&!$mysql)throw new RuntimeException('A ativação MySQL exige uma instalação existente.');
        if(file_put_contents($private.'/storage/deploy.maintenance','Deployment in progress')===false){
            throw new RuntimeException('Não foi possível suspender o site.');
        }
        foreach(['src','database/migrations','bin','vendor','deploy','docs'] as $dir)$copy($repository.'/'.$dir,$private.'/'.$dir);
        foreach(['database/seed.php','composer.json','composer.lock','.env.example','.htaccess'] as $file)$copy($repository.'/'.$file,$private.'/'.$file);
        if(!is_dir($private.'/public'))mkdir($private.'/public',0700);
        foreach(['app.html','openapi.json'] as $file)$copy($repository.'/public/'.$file,$private.'/public/'.$file);
        foreach(['assets','brand'] as $dir)$copy($repository.'/public/'.$dir,$public.'/'.$dir);
        foreach(['docs.html','openapi.json'] as $file)$copy($repository.'/public/'.$file,$public.'/'.$file);
        // Retain PHP handlers and hosting settings added by cPanel after first deployment.
        if(!is_file($public.'/.htaccess'))$copy($repository.'/public/.htaccess',$public.'/.htaccess');
        if(!is_file($public.'/.user.ini'))file_put_contents($public.'/.user.ini',"display_errors=Off\nlog_errors=On\nupload_max_filesize=10M\npost_max_size=12M\n");
        if(!is_file($private.'/.env')&&!is_file($private.'/storage/installed.lock')&&!is_file($private.'/storage/install.key')){file_put_contents($private.'/storage/install.key',bin2hex(random_bytes(32)));chmod($private.'/storage/install.key',0600);}
        if(!$mysql&&is_file($private.'/.env')){
            // Load the newly deployed classes, never the source checkout's application classes.
            require_once $private.'/src/Database.php';
            (new HomeAlone\Database($dbPath))->migrate();
        }
        $entry="<?php\ndeclare(strict_types=1);\nrequire ".var_export($private.'/deploy/web.php',true).";\n";
        $temp=$public.'/index.php.deploy';file_put_contents($temp,$entry);rename($temp,$public.'/index.php');
        if($pendingEnv!==null){
            $stage=$private.'/.env.mysql-'.bin2hex(random_bytes(6));
            $mask=umask(0077);
            try {
                if(file_put_contents($stage,$pendingEnv)===false||!rename($stage,$private.'/.env')){
                    throw new RuntimeException('Não foi possível ativar o MySQL.');
                }
            } finally {
                umask($mask);
                if(is_file($stage))unlink($stage);
            }
        }
        unlink($private.'/storage/deploy.maintenance');
        return ['private'=>$private,'public'=>$public,'backup'=>$backup,'installed'=>is_file($private.'/.env')];
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function rendoraVerifyMysqlCopy(PDO $sqlite,PDO $mysql): void {
    if($sqlite->query('PRAGMA foreign_key_check')->fetch()){
        throw new RuntimeException('A origem SQLite contém relações inválidas.');
    }
    $excluded=['migrations','sessions','reset_tokens','rate_limits'];
    foreach($sqlite->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(in_array($table,$excluded,true))continue;
        if(!preg_match('/^[a-z_]+$/D',$table))throw new RuntimeException('Tabela SQLite inesperada.');
        $columns=array_column($sqlite->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC),'name');
        foreach($columns as $column)if(!preg_match('/^[a-z_]+$/D',$column))throw new RuntimeException('Coluna SQLite inesperada.');
        $fields='`'.implode('`,`',$columns).'`';
        $sql="SELECT $fields FROM `$table`";
        $rows=static function(PDO $db)use($sql):array {
            $result=[];
            foreach($db->query($sql)->fetchAll(PDO::FETCH_NUM) as $row){
                $result[]=json_encode(array_map(static fn($value)=>$value===null?null:(string)$value,$row),JSON_THROW_ON_ERROR);
            }
            sort($result,SORT_STRING);
            return $result;
        };
        if($rows($sqlite)!==$rows($mysql))throw new RuntimeException('A cópia MySQL diverge da origem na tabela '.$table.'.');
    }
    if($mysql->query('SELECT 1 FROM sessions LIMIT 1')->fetchColumn()
        ||$mysql->query('SELECT 1 FROM reset_tokens LIMIT 1')->fetchColumn()){
        throw new RuntimeException('A cópia MySQL contém sessões ou tokens de recuperação.');
    }
}
if(PHP_SAPI==='cli' &&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    try{$result=rendoraDeploy(dirname(__DIR__),getenv('HOME')?:'',in_array('--activate-mysql',$argv,true));echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\nDeploy concluído. Configura o document root para a pasta public indicada.\n";}
    catch(Throwable $e){fwrite(STDERR,'Rendora: '.$e->getMessage()."\n");exit(1);}
}
