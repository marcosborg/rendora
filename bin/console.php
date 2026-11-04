<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use HomeAlone\{Database,Jobs};
$command=$argv[1]??'help';
try{
    $db=database();
    switch($command){
        case 'migrate':$db->migrate();echo "Migrações aplicadas.\n";break;
        case 'seed':if(env('APP_ENV')==='production'||env('DEMO_MODE')!=='true')throw new RuntimeException('Demonstração só permitida fora de produção com DEMO_MODE=true.');require dirname(__DIR__).'/database/seed.php';seed($db);echo "Duas empresas de demonstração preparadas.\n";break;
        case 'cron':$jobs=new Jobs($db);$created=$jobs->generateMonthly();$jobs->reminders();echo json_encode(['charges_created'=>$created,...$jobs->work()])."\n";break;
        case 'backup':$dir=dirname(__DIR__).'/storage/backups';if(!is_dir($dir))mkdir($dir,0700,true);$target=$dir.'/backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';$db->pdo->exec('VACUUM INTO '.$db->pdo->quote($target));chmod($target,0600);echo $target."\n";break;
        case 'verify-backup':$path=$argv[2]??'';if(!is_file($path))throw new RuntimeException('Indique o caminho do backup.');$backup=new Database($path);$result=$backup->one('PRAGMA integrity_check');if(array_values($result)[0]!=='ok'||$backup->all('PRAGMA foreign_key_check'))throw new RuntimeException('Backup inválido.');echo "Backup íntegro. ".$backup->one('SELECT COUNT(*) n FROM companies')['n']." empresas.\n";break;
        case 'admin':$email=$argv[2]??'';if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Uso: php bin/console.php admin email (palavra-passe via stdin)');$pass=trim(stream_get_contents(STDIN));if(strlen($pass)<12)throw new RuntimeException('Mínimo de 12 caracteres.');$u=$db->one('SELECT id FROM users WHERE email=?',[$email]);if($u)$db->run('UPDATE users SET platform_admin=1 WHERE id=?',[$u['id']]);else $db->insert('users',['name'=>'Administrador da plataforma','email'=>$email,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'platform_admin'=>1]);echo "Administrador preparado.\n";break;
        default:echo "Comandos: migrate, seed, cron, backup, verify-backup <ficheiro>, admin <email>\n";
    }
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
