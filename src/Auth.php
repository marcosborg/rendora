<?php
declare(strict_types=1);
namespace HomeAlone;
use Psr\Http\Message\ServerRequestInterface as Request;
final class Auth {
    public function __construct(private Database $db) {}
    public function throttle(string $key,int $limit=10,int $seconds=900): void {
        $key=hash('sha256',$key);$now=time();
        $this->db->transaction(function()use($key,$now,$limit,$seconds){
            $row=$this->db->one('SELECT * FROM rate_limits WHERE key=?',[$key]);
            if($row&&$row['expires_at']>$now&&$row['attempts']>=$limit)throw new HttpError(429,'Demasiadas tentativas. Tente mais tarde.');
            $active=$row && $row['expires_at']>$now;
            $attempts=$active ? $row['attempts']+1 : 1;
            $expiresAt=$active ? $row['expires_at'] : $now+$seconds;
            if($row){
                $this->db->run('UPDATE rate_limits SET attempts=?,expires_at=? WHERE key=?',[$attempts,$expiresAt,$key]);
            }else{
                $this->db->run('INSERT INTO rate_limits(key,attempts,expires_at) VALUES(?,?,?)',[$key,$attempts,$expiresAt]);
            }
        });
    }
    public function login(array $data,string $ip): array {
        $this->throttle('login:'.$ip,25);$email=Validate::email($data);$this->throttle('email:'.$email,10);
        $user=$this->db->one('SELECT * FROM users WHERE email=?',[$email]);
        $password=Validate::text($data,'password',1,1024);
        if(!$user||!password_verify($password,$user['password_hash']))throw new HttpError(401,'Email ou palavra-passe incorretos.');
        return $this->session($user);
    }
    public function session(array $user): array {
        $token=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(24));
        $this->db->insert('sessions',['user_id'=>$user['id'],'token_hash'=>hash('sha256',$token),'csrf'=>$csrf,'expires_at'=>gmdate('Y-m-d H:i:s',time()+86400)]);
        return ['token'=>$token,'csrf'=>$csrf,'user'=>['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email'],'platform_admin'=>(bool)($user['platform_admin']??false)],'expires_in'=>86400];
    }
    public function user(Request $request): array {
        $bearer=$request->getHeaderLine('Authorization');$token=str_starts_with($bearer,'Bearer ')?substr($bearer,7):($request->getCookieParams()['ha_session']??'');
        if(!$token)throw new HttpError(401,'Inicie sessão para continuar.');
        $row=$this->db->one('SELECT u.id,u.name,u.email,u.platform_admin,s.csrf,s.id session_id FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>CURRENT_TIMESTAMP',[hash('sha256',$token)]);
        if(!$row)throw new HttpError(401,'Sessão expirada. Inicie sessão novamente.');
        if(!str_starts_with($bearer,'Bearer ')&&!in_array($request->getMethod(),['GET','HEAD','OPTIONS'])&&!hash_equals($row['csrf'],$request->getHeaderLine('X-CSRF-Token')))throw new HttpError(403,'Token de segurança inválido. Atualize a página.');
        return $row;
    }
    public function scope(Request $request): Scope {
        $user=$this->user($request);$company=$request->getHeaderLine('X-Company-Id');
        $membership=$this->db->one('SELECT * FROM memberships WHERE company_id=? AND user_id=? AND active=1',[$company,$user['id']]);
        if(!$membership)throw new HttpError(403,'Sem acesso a esta empresa.');
        return new Scope($this->db,$company,$user,$membership['role']);
    }
    public function register(array $d,string $ip): array {
        $this->throttle('register:'.$ip,5,3600);$name=Validate::text($d,'name',2,100);$email=Validate::email($d);$pass=Validate::text($d,'password',12,200);$company=Validate::text($d,'company',2,100);$slug=Validate::text($d,'slug',3,60);
        if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug))throw new HttpError(422,'Use letras minúsculas, números e hífen no endereço.');
        return $this->db->transaction(function()use($name,$email,$pass,$company,$slug){
            if($this->db->one('SELECT id FROM users WHERE email=?',[$email])||$this->db->one('SELECT id FROM companies WHERE slug=?',[$slug]))throw new HttpError(409,'Email ou endereço já utilizado.');
            $u=$this->db->insert('users',['name'=>$name,'email'=>$email,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT)]);
            $c=$this->db->insert('companies',['name'=>$company,'email'=>$email,'slug'=>$slug]);
            $this->db->insert('memberships',['company_id'=>$c['id'],'user_id'=>$u['id'],'role'=>'ADMIN']);
            return [...$this->session($u),'company_id'=>$c['id']];
        });
    }
    public function resetRequest(array $d,string $ip): void {
        $this->throttle('reset:'.$ip,5);$email=Validate::email($d);$u=$this->db->one('SELECT id FROM users WHERE email=?',[$email]);if(!$u)return;
        $token=bin2hex(random_bytes(32));
        $this->db->transaction(function()use($u,$token,$email){
            $this->db->run('DELETE FROM reset_tokens WHERE user_id=?',[$u['id']]);
            $this->db->insert('reset_tokens',['user_id'=>$u['id'],'token_hash'=>hash('sha256',$token),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)]);
            $this->db->insert('jobs',['type'=>'EMAIL','payload'=>json_encode(['email'=>$email,'subject'=>'Definir palavra-passe · Rendora','body'=>'Defina a sua palavra-passe: '.env('APP_URL').'/?reset='.$token]),'dedupe_key'=>'reset:'.hash('sha256',$token)]);
        });
    }
    public function reset(array $d): void {
        $token=Validate::text($d,'token',64,64);$pass=Validate::text($d,'password',12,200);
        $this->db->transaction(function()use($token,$pass){$r=$this->db->one('SELECT * FROM reset_tokens WHERE token_hash=? AND expires_at>CURRENT_TIMESTAMP',[hash('sha256',$token)]);if(!$r)throw new HttpError(422,'Ligação inválida ou expirada.');$this->db->run('UPDATE users SET password_hash=?,email_verified_at=CURRENT_TIMESTAMP WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$r['user_id']]);$this->db->run('UPDATE memberships SET active=1,invitation_pending=0 WHERE user_id=? AND invitation_pending=1',[$r['user_id']]);$this->db->run('DELETE FROM sessions WHERE user_id=?',[$r['user_id']]);$this->db->run('DELETE FROM reset_tokens WHERE user_id=?',[$r['user_id']]);});
    }
}
