<?php
declare(strict_types=1);
namespace HomeAlone;

/** Only the cPanel front controller exposes this, and only before .env exists. */
final class Installer {
    public static function install(string $root,array $input): void {
        $lock=fopen($root.'/storage/install-process.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Instalação ocupada.');
        $tempDb=null;
        try {
            if(is_file($root.'/.env')||is_file($root.'/storage/installed.lock'))throw new HttpError(409,'Esta instalação já foi concluída.');
            $key=is_file($root.'/storage/install.key')?trim(file_get_contents($root.'/storage/install.key')):'';
            if(strlen($key)!==64||!hash_equals($key,trim((string)($input['key']??''))))throw new HttpError(403,'Chave de instalação inválida.');
            $name=Validate::text($input,'name',2,100);$email=Validate::email($input);$password=Validate::text($input,'password',12,200);
            if($password!==($input['confirmation']??null))throw new HttpError(422,'As palavras-passe não coincidem.');
            $url=rtrim(Validate::text($input,'url',8,255),'/');
            if(!preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~i',$url))throw new HttpError(422,'Indica o endereço HTTPS do domínio, sem subpastas.');
            foreach(['pdo_sqlite','mbstring','curl','fileinfo','iconv','openssl','zlib'] as $extension)if(!extension_loaded($extension))throw new HttpError(503,'Extensão PHP em falta: '.$extension);
            if(is_file($root.'/storage/homealone.sqlite'))throw new HttpError(409,'Já existe uma base de dados. Recupera a configuração pelo Gestor de Ficheiros; não reinstales sobre os dados.');
            $tempDb=$root.'/storage/install-'.bin2hex(random_bytes(12)).'.sqlite';
            $db=new Database($tempDb);$db->migrate();
            $db->insert('users',['name'=>$name,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'platform_admin'=>1,'email_verified_at'=>gmdate('Y-m-d H:i:s')]);
            if(array_values($db->one('PRAGMA integrity_check'))[0]!=='ok')throw new \RuntimeException('Base de dados inválida.');
            unset($db);chmod($tempDb,0600);
            $env="APP_ENV=production\nAPP_URL=".$url."\nDB_PATH=storage/homealone.sqlite\nDEMO_MODE=false\nCOOKIE_SECURE=true\nSTRIPE_SECRET_KEY=\nSTRIPE_WEBHOOK_SECRET=\nSTRIPE_BILLING_WEBHOOK_SECRET=\nALLOW_LIVE_PAYMENTS=false\nRESEND_API_KEY=\nEMAIL_FROM=\n";
            $envTemp=$root.'/.env.install';
            if(file_put_contents($envTemp,$env)===false)throw new \RuntimeException('Não foi possível guardar a configuração.');
            chmod($envTemp,0600);
            if(!rename($tempDb,$root.'/storage/homealone.sqlite'))throw new \RuntimeException('Não foi possível guardar a base de dados.');
            $tempDb=null;
            if(!rename($envTemp,$root.'/.env'))throw new \RuntimeException('Não foi possível ativar a configuração. Recupera .env.install no Gestor de Ficheiros.');
            file_put_contents($root.'/storage/installed.lock',gmdate('c'));chmod($root.'/storage/installed.lock',0600);
            unlink($root.'/storage/install.key');
        } finally {if($tempDb&&is_file($tempDb))unlink($tempDb);flock($lock,LOCK_UN);fclose($lock);}
    }

    public static function page(string $root): void {
        header('Cache-Control: no-store');header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        $https=($_SERVER['HTTPS']??'')==='on'||($_SERVER['SERVER_PORT']??'')==='443';
        $host=strtolower($_SERVER['HTTP_HOST']??'');
        if(!$https){http_response_code(403);echo 'Ativa o certificado SSL e abre o domínio por HTTPS para instalar a Rendora.';return;}
        if(!preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/',$host)){http_response_code(400);return;}
        session_name('rendora_install');session_start(['cookie_secure'=>true,'cookie_httponly'=>true,'cookie_samesite'=>'Strict','use_strict_mode'=>true]);
        $_SESSION['csrf']??=bin2hex(random_bytes(32));$error='';$success=false;
        if($_SERVER['REQUEST_METHOD']==='POST'){
            try {
                if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new HttpError(403,'Atualiza a página e tenta novamente.');
                if(rtrim((string)($_POST['url']??''),'/')!=='https://'.$host)throw new HttpError(422,'O endereço deve corresponder ao domínio HTTPS aberto.');
                self::install($root,$_POST);$success=true;$_SESSION=[];session_destroy();
            } catch(HttpError $e){http_response_code($e->status);$error=$e->getMessage();}
              catch(\Throwable $e){http_response_code(500);$error='Não foi possível concluir. Verifica as permissões de escrita e o registo de erros do alojamento.';error_log('Rendora installer: '.get_class($e));}
        }
        $esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
        echo '<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><link rel="icon" href="/brand/favicon.svg"><title>Instalar Rendora</title><style>body{margin:0;background:#f5f8f7;color:#173b3a;font:16px system-ui}main{max-width:560px;margin:40px auto;padding:28px;background:white;border:1px solid #dce6e3;border-radius:12px}img{width:180px}h1{font-size:26px}p{line-height:1.6;color:#536b69}label{display:block;margin:18px 0}input{display:block;box-sizing:border-box;width:100%;padding:12px;margin-top:8px;border:1px solid #6d8782;border-radius:7px;font:inherit}button,a{background:#125c59;color:white;border:0;border-radius:7px;padding:13px 18px;font:inherit;display:inline-block}input:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid #007b76;outline-offset:2px}.error{padding:12px;background:#fff0ee;color:#9c3832}@media(max-width:620px){main{margin:15px;padding:22px}}</style></head><body><main><img src="/brand/rendora-light.svg" alt="Rendora">';
        if($success){echo '<h1>Instalação concluída</h1><p>O administrador global foi criado. Entra e abre Plataforma → Nova empresa. O instalador foi bloqueado.</p><a href="/">Iniciar sessão</a>';}
        else {
            echo '<h1>Preparar a tua Rendora</h1><p>No Gestor de Ficheiros do cPanel, abre a pasta privada <strong>rendora/storage/install.key</strong> (ou a pasta privada que configuraste) e copia a chave. Não é criada qualquer empresa de demonstração.</p>';
            if($error)echo '<p class="error" role="alert">'.$esc($error).'</p>';
            echo '<form method="post"><input type="hidden" name="csrf" value="'.$esc($_SESSION['csrf']).'">';
            foreach([['key','Chave de instalação','password',''],['url','Endereço HTTPS','url','https://'.$host],['name','Nome do administrador global','text',''],['email','Email do administrador global','email',''],['password','Palavra-passe (mínimo 12 caracteres)','password',''],['confirmation','Repetir palavra-passe','password','']] as [$name,$label,$type,$value])echo '<label>'.$label.'<input required name="'.$name.'" type="'.$type.'" autocomplete="'.(in_array($name,['password','confirmation'])?'new-password':($name==='key'?'off':$name)).'" '.(in_array($name,['password','confirmation'])?'minlength="12"':'').' value="'.$esc($value).'"></label>';
            echo '<p>Pagamentos reais e emails ficam desativados até serem configurados.</p><button>Concluir instalação</button></form>';
        }
        echo '</main></body></html>';
    }
}
