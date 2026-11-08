<?php
declare(strict_types=1);
/** Deliberately does not invoke npm, Composer, shell commands or fetch dependencies. */
function rendoraDeploy(string $repository,string $accountHome,bool $demo=false): array {
    if(PHP_VERSION_ID<80200)throw new RuntimeException('PHP 8.2 ou superior necessário.');
    foreach(['pdo_sqlite','mbstring','curl','fileinfo','iconv','openssl','zlib'] as $extension)if(!extension_loaded($extension))throw new RuntimeException('Extensão PHP em falta: '.$extension);
    $repository=realpath($repository)?:throw new RuntimeException('Repositório não encontrado.');
    $home=realpath($accountHome)?:throw new RuntimeException('Pasta da conta não encontrada.');
    $configFile=$home.'/.rendora-deploy.json';
    $config=!$demo&&is_file($configFile)?json_decode(file_get_contents($configFile),true,512,JSON_THROW_ON_ERROR):[];
    $resolve=function(string $relative)use($home):string {
        $relative=str_replace('\\','/',$relative);
        if(!preg_match('~^[a-zA-Z0-9_./-]+$~',$relative)||str_starts_with($relative,'/')||preg_match('~(^|/)\.\.?(/|$)~',$relative))throw new RuntimeException('O destino deve ser um caminho relativo seguro dentro da conta.');
        $path=$home;
        foreach(explode('/',trim($relative,'/')) as $part){if($part==='')continue;$path.='/'.$part;if(is_link($path))throw new RuntimeException('Destinos com ligações simbólicas não são permitidos.');}
        return $path;
    };
    $private=$resolve($demo?'rendora-demo':($config['private_path']??'rendora'));
    $public=$resolve($demo?'demo_public':($config['public_path']??'public_html/rendora'));
    $contains=fn($a,$b)=>$a===$b||str_starts_with(str_replace('\\','/',$b).'/',str_replace('\\','/',$a).'/');
    if($contains($private,$public)||$contains($public,$private)||$contains($repository,$private)||$contains($private,$repository)||$contains($repository,$public)||$contains($public,$repository)||$contains($home.'/public_html',$private))throw new RuntimeException('Código privado, repositório e pasta pública devem estar separados.');
    // Adopt only the existing demo layout installed by our Apache package.
    $existingDemo=$demo&&is_file($public.'/index.php')&&!is_link($public.'/index.php')
        && str_contains(file_get_contents($public.'/index.php'),"define('RENDORA_PUBLIC_DEMO', true);")
        && is_file($private.'/src/bootstrap.php')&&!is_file($private.'/.env');
    foreach([$private,$public] as $directory){
        if(is_dir($directory)&&count(array_diff(scandir($directory),['.','..']))&&!is_file($directory.'/.rendora-deployment.json')&&!$existingDemo)throw new RuntimeException('Destino já contém ficheiros não geridos pela Rendora: '.$directory);
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
    if($demo){
        // Prepare the existing demo generation before publishing code that uses it.
        if ($existingDemo) rendoraPrepareDemoCleaning($private, $repository);
        foreach([$private,$public] as $dir)if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new RuntimeException('Sem permissões para criar o destino.');
        foreach(['src','vendor'] as $dir)$copy($repository.'/'.$dir,$private.'/'.$dir);
        if(!is_dir($private.'/public')&&!mkdir($private.'/public',0755))throw new RuntimeException('Sem permissões para criar a pasta da interface.');
        foreach(['assets','brand'] as $dir)$copy($repository.'/public/'.$dir,$public.'/'.$dir);
        foreach(['docs.html','openapi.json'] as $file)$copy($repository.'/public/'.$file,$public.'/'.$file);
        if(!is_file($public.'/.htaccess'))$copy($repository.'/public/.htaccess',$public.'/.htaccess');
        $copy($repository.'/public/app.html',$private.'/public/app.html');
        $copy($repository.'/deploy/demo.php',$public.'/index.php');
        return ['private'=>$private,'public'=>$public,'demo'=>true];
    }
    foreach([$private,$public,$private.'/storage',$private.'/storage/backups'] as $dir)if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Sem permissões para criar o destino.');
    chmod($public,0755);
    foreach([$private,$public] as $dir)file_put_contents($dir.'/.rendora-deployment.json',json_encode(['private'=>$private,'public'=>$public],JSON_THROW_ON_ERROR));
    $lock=fopen($private.'/storage/runtime.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('A aplicação está ocupada. Repete Deploy HEAD Commit.');
    $backup=null;
    try {
        // Existing config is read only. Runtime files are never copied from Git.
        if(is_file($private.'/.env')){
            $settings=Dotenv\Dotenv::parse(file_get_contents($private.'/.env'));
            $dbPath=$settings['DB_PATH']??'storage/homealone.sqlite';
            if(!str_starts_with($dbPath,'/')&&!preg_match('/^[A-Za-z]:/',$dbPath))$dbPath=$private.'/'.$dbPath;
            if(is_file($dbPath)){
                $pdo=new PDO('sqlite:'.$dbPath);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
                $backup=$private.'/storage/backups/pre-deploy-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
                $pdo->exec('VACUUM INTO '.$pdo->quote($backup));chmod($backup,0600);$pdo=null;
            }
        }
        file_put_contents($private.'/storage/deploy.maintenance','Deployment in progress');
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
        if(is_file($private.'/.env')){
            // Load the newly deployed classes, never the source checkout's application classes.
            require_once $private.'/src/Database.php';
            (new HomeAlone\Database($dbPath))->migrate();
        }
        $entry="<?php\ndeclare(strict_types=1);\nrequire ".var_export($private.'/deploy/web.php',true).";\n";
        $temp=$public.'/index.php.deploy';file_put_contents($temp,$entry);rename($temp,$public.'/index.php');
        unlink($private.'/storage/deploy.maintenance');
        return ['private'=>$private,'public'=>$public,'backup'=>$backup,'installed'=>is_file($private.'/.env')];
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function rendoraPrepareDemoCleaning(string $private, string $repository): void {
    $storage = $private . '/storage';
    $generations = glob($storage . '/demo-*.sqlite');
    $path = $generations ? end($generations) : false;
    if (is_link($storage) || !$path || !preg_match('/^demo-[0-9]{14}\.sqlite$/D', basename($path))
        || !is_file($path) || is_link($path)) {
        throw new RuntimeException('Base SQLite da demo indisponível.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ((int)$db->query('PRAGMA application_id')->fetchColumn() !== 1380205636) {
        throw new RuntimeException('Base da demo inválida.');
    }
    $db->exec('PRAGMA busy_timeout=10000');
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $migration = '005_cleaning.sql';
        $applied = $db->prepare('SELECT name FROM migrations WHERE name=?');
        $applied->execute([$migration]);
        if (!$applied->fetchColumn()) {
            $db->exec(file_get_contents($repository . '/database/migrations/' . $migration));
            $seed = $db->prepare("INSERT INTO cleaning_plans
                (id, company_id, property_id, assignee_id, start_date, interval_days, visit_time)
                SELECT 'cleaning-estrela', p.company_id, p.id, m.user_id, ?, 7, '10:00'
                FROM properties p JOIN memberships m ON m.company_id=p.company_id
                WHERE p.company_id='company-lisbon' AND p.id='p-estrela'
                AND m.user_id='u-tech' AND m.role='TECHNICIAN' AND m.active=1");
            $month = new DateTimeImmutable('first day of this month', new DateTimeZone('Europe/Lisbon'));
            $seed->execute([$month->format('Y-m-d')]);
            $db->prepare('INSERT INTO migrations(name) VALUES(?)')->execute([$migration]);
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}
if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    try{$result=rendoraDeploy(dirname(__DIR__),getenv('HOME')?:'',in_array('--demo',$argv,true));echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\nDeploy concluído. Configura o document root para a pasta public indicada.\n";}
    catch(Throwable $e){fwrite(STDERR,'Rendora: '.$e->getMessage()."\n");exit(1);}
}
