<?php
declare(strict_types=1);
namespace HomeAlone;
final class Administration {
    public function __construct(private Database $db) {}
    private function requirePlatform(array $actor): void {
        if(empty($actor['platform_admin']))throw new HttpError(403,'Acesso reservado à administração da plataforma.');
    }
    public function platform(array $actor): array {
        $this->requirePlatform($actor);
        $companies=$this->db->all('SELECT id,name,slug,subscription_status,plan_id FROM companies ORDER BY created_at DESC,name');
        $admins=$this->db->all("SELECT m.company_id,u.id,u.name,u.email,m.active,m.invitation_pending FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.role='ADMIN' ORDER BY u.name");
        foreach($companies as &$company)$company['administrators']=array_values(array_filter($admins,fn($a)=>$a['company_id']===$company['id']));
        unset($company);
        return ['companies'=>$companies,'plans'=>$this->db->all('SELECT * FROM plans'),'email_configured'=>(bool)(env('RESEND_API_KEY')&&env('EMAIL_FROM')),'demo'=>env('DEMO_MODE')==='true'];
    }
    public function createCompany(array $actor,array $d): array {
        $this->requirePlatform($actor);
        $name=Validate::text($d,'name',2,100);$email=Validate::email($d);$slug=Validate::text($d,'slug',3,60);
        $adminName=Validate::text($d,'admin_name',2,150);$adminEmail=Validate::email(['email'=>$d['admin_email']??'']);
        if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug))throw new HttpError(422,'O endereço deve usar letras minúsculas, números e hífen.');
        return $this->db->transaction(function()use($actor,$name,$email,$slug,$adminName,$adminEmail){
            if($this->db->one('SELECT id FROM companies WHERE slug=?',[$slug]))throw new HttpError(409,'Este endereço já pertence a outra empresa.');
            $company=$this->db->insert('companies',['name'=>$name,'email'=>$email,'slug'=>$slug,'color'=>'#125c59']);
            // Explicit platform operation: no implicit membership or access to business data for the actor.
            $scope=new Scope($this->db,$company['id'],$actor,'ADMIN');
            $admin=$this->invite($scope,['name'=>$adminName,'email'=>$adminEmail,'role'=>'ADMIN']);
            $scope->audit('platform.company_created',$company['id']);
            $membership=$this->db->one('SELECT active,invitation_pending FROM memberships WHERE company_id=? AND user_id=?',[$company['id'],$admin['id']]);
            return ['id'=>$company['id'],'name'=>$name,'slug'=>$slug,'administrator'=>['id'=>$admin['id'],'name'=>$admin['name'],'email'=>$admin['email'],...$membership]];
        });
    }
    public function resendCompanyInvite(array $actor,string $companyId,string $userId): array {
        $this->requirePlatform($actor);
        return $this->db->transaction(function()use($actor,$companyId,$userId){
            $member=$this->db->one("SELECT u.email FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.company_id=? AND m.user_id=? AND m.role='ADMIN' AND m.invitation_pending=1",[$companyId,$userId]);
            if(!$member)throw new HttpError(404,'Convite de administrador pendente não encontrado.');
            (new Auth($this->db))->resetRequest(['email'=>$member['email']],'platform-invite:'.$companyId.':'.$userId);
            (new Scope($this->db,$companyId,$actor,'ADMIN'))->audit('platform.admin_invitation_resent',$userId);
            return ['queued'=>true];
        });
    }
    public function settings(Scope $s): array {
        $s->requireRoles('ADMIN','MANAGER');$c=$this->db->one('SELECT * FROM companies WHERE id=?',[$s->companyId]);
        $c['integrations']=['payments'=>env('STRIPE_SECRET_KEY')!==''?(env('ALLOW_LIVE_PAYMENTS')==='true'?'live':'test'):'unconfigured','connected'=>(bool)$c['stripe_account_id'],'email'=>env('RESEND_API_KEY')&&env('EMAIL_FROM')?'configured':'unconfigured','storage'=>'local_private','database'=>'SQLite','demo'=>env('DEMO_MODE')==='true'];
        $c['jobs']=$this->db->all('SELECT status,COUNT(*) count FROM jobs WHERE company_id=? GROUP BY status',[$s->companyId]);$c['plans']=$this->db->all('SELECT * FROM plans WHERE active=1');return $c;
    }
    public function update(Scope $s,array $d): array {
        $s->requireRoles('ADMIN');$data=['name'=>Validate::text($d,'name',2,150),'email'=>Validate::email($d),'phone'=>Validate::text($d,'phone',0,40),'description'=>Validate::text($d,'description',0,10000),'email_template'=>Validate::text($d,'email_template',10,2000),'color'=>Validate::text($d,'color',7,7)];if(!preg_match('/^#[a-f0-9]{6}$/i',$data['color']))throw new HttpError(422,'Cor inválida.');$days=$d['reminder_days']??[-5,0,3];if(!is_array($days)||count($days)>10)throw new HttpError(422,'Lembretes inválidos.');foreach($days as $day)if(!is_int($day)||$day< -30||$day>90)throw new HttpError(422,'Intervalo de lembrete inválido.');$data['reminder_days']=json_encode(array_values(array_unique($days)));$this->db->run('UPDATE companies SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($data))).' WHERE id=?',[...array_values($data),$s->companyId]);$s->audit('company.updated',$s->companyId);return $this->settings($s);
    }
    public function members(Scope $s): array {
        if($s->staff())return $this->db->all('SELECT u.id,u.name,u.email,m.role,m.active,m.invitation_pending FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.company_id=? ORDER BY u.name',[$s->companyId]);
        $rows=$this->db->all("SELECT u.id,u.name,m.role FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.company_id=? AND m.active=1 AND (m.role IN('ADMIN','MANAGER') OR m.user_id=?)",[$s->companyId,$s->user['id']]);
        if($s->role==='RESIDENT')foreach((new Rentals($this->db))->properties($s) as $p)if($p['owner_id']){$u=$this->db->one("SELECT u.id,u.name,m.role FROM users u JOIN memberships m ON m.user_id=u.id WHERE u.id=? AND m.company_id=? AND m.active=1",[$p['owner_id'],$s->companyId]);if($u&&!in_array($u['id'],array_column($rows,'id')))$rows[]=$u;}if($s->role==='OWNER')foreach($this->db->all("SELECT DISTINCT u.id,u.name,m.role FROM users u JOIN contacts co ON co.user_id=u.id JOIN contract_residents cr ON cr.contact_id=co.id JOIN contracts c ON c.id=cr.contract_id JOIN properties p ON p.id=c.property_id JOIN memberships m ON m.user_id=u.id AND m.company_id=c.company_id WHERE c.company_id=? AND p.owner_id=? AND c.status='ACTIVE' AND c.start_date<=business_today() AND COALESCE(c.end_date,'9999-12-31')>=business_today() AND m.active=1 AND m.role='RESIDENT'",[$s->companyId,$s->user['id']]) as $resident)if(!in_array($resident['id'],array_column($rows,'id')))$rows[]=$resident;return $rows;
    }
    public function invite(Scope $s,array $d): array {
        $s->requireRoles('ADMIN');$email=Validate::email($d);$role=Validate::choice($d,'role',['ADMIN','MANAGER','FINANCE','TECHNICIAN','OWNER','RESIDENT']);$name=Validate::text($d,'name',2,150);
        return $this->db->transaction(function()use($s,$email,$role,$name){$limit=$this->db->one('SELECT p.member_limit FROM companies c JOIN plans p ON p.id=c.plan_id WHERE c.id=?',[$s->companyId]);if($limit&&$this->db->one('SELECT COUNT(*) n FROM memberships WHERE company_id=? AND active=1',[$s->companyId])['n']>=$limit['member_limit'])throw new HttpError(409,'Limite de utilizadores atingido.');$u=$this->db->one('SELECT * FROM users WHERE email=?',[$email]);$new=!$u||empty($u['email_verified_at']);if(!$u)$u=$this->db->insert('users',['name'=>$name,'email'=>$email,'password_hash'=>password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);if($this->db->one('SELECT id FROM memberships WHERE company_id=? AND user_id=?',[$s->companyId,$u['id']]))throw new HttpError(409,'Este utilizador já pertence à empresa.');$this->db->insert('memberships',['company_id'=>$s->companyId,'user_id'=>$u['id'],'role'=>$role,'active'=>$new?0:1,'invitation_pending'=>$new?1:0]);if($role==='RESIDENT')$this->db->run('UPDATE contacts SET user_id=? WHERE company_id=? AND email=?',[$u['id'],$s->companyId,$email]);if($new){(new Auth($this->db))->resetRequest(['email'=>$email],'invite:'.$s->companyId.':'.$u['id']);}else(new Operations($this->db))->notify($s->companyId,$u['id'],'Convite para a empresa','Já pode aceder à empresa no portal.','invite:'.$s->companyId.':'.$u['id']);$s->audit('member.invited',$u['id']);return ['id'=>$u['id'],'name'=>$u['name'],'email'=>$email,'role'=>$role,'email_configured'=>(bool)env('RESEND_API_KEY')];});
    }
    public function updateMember(Scope $s,string $id,array $d): array {
        $s->requireRoles('ADMIN');$member=$this->db->one('SELECT * FROM memberships WHERE company_id=? AND user_id=?',[$s->companyId,$id]);if(!$member)throw new HttpError(404,'Utilizador não encontrado.');if($member['invitation_pending'])throw new HttpError(409,'O convite deve ser aceite por email antes de ativar o acesso.');if($id===$s->user['id'])throw new HttpError(422,'Não pode alterar o seu próprio acesso.');$role=Validate::choice($d,'role',['ADMIN','MANAGER','FINANCE','TECHNICIAN','OWNER','RESIDENT']);$active=!empty($d['active'])?1:0;$this->db->run('UPDATE memberships SET role=?,active=? WHERE company_id=? AND user_id=?',[$role,$active,$s->companyId,$id]);$s->audit('member.updated',$id);return ['id'=>$id,'role'=>$role,'active'=>$active];
    }
    public function publicCompany(string $slug): array {
        $c=$this->db->one('SELECT id,slug,name,email,phone,description,color FROM companies WHERE slug=?',[$slug]);if(!$c)throw new HttpError(404,'Empresa não encontrada.');$props=$this->db->all("SELECT id,name,type,address,city,description,bedrooms,area,rent_cents,photos,amenities FROM properties WHERE company_id=? AND published=1 AND type IN('APARTMENT','ROOM') ORDER BY name",[$c['id']]);foreach($props as &$p){$p['photos']=json_decode($p['photos'],true);$p['amenities']=json_decode($p['amenities'],true);$source=$this->db->one('SELECT parent_id FROM properties WHERE id=?',[$p['id']]);$p['available']=!$this->db->one("SELECT id FROM contracts WHERE company_id=? AND status<>'CANCELLED' AND start_date<=business_today() AND COALESCE(end_date,'9999-12-31')>=business_today() AND (property_id=? OR property_id IN(SELECT id FROM properties WHERE parent_id=?) OR property_id=?)",[$c['id'],$p['id'],$p['id'],$source['parent_id']]);}unset($p);return ['company'=>$c,'properties'=>$props];
    }
    public function publicLead(string $slug,array $d,string $ip): array {
        (new Auth($this->db))->throttle('lead:'.$ip,5,3600);if(!empty($d['website']))throw new HttpError(422,'Pedido inválido.');if(empty($d['consent']))throw new HttpError(422,'Confirme a autorização de contacto.');$company=$this->publicCompany($slug)['company'];$p=$this->db->one('SELECT id FROM properties WHERE id=? AND company_id=? AND published=1',[Validate::text($d,'property_id'),$company['id']]);if(!$p)throw new HttpError(404,'Alojamento não encontrado.');
        return $this->db->transaction(function()use($d,$company,$p){$c=$this->db->insert('contacts',['company_id'=>$company['id'],'name'=>Validate::text($d,'name',2,150),'email'=>Validate::email($d),'phone'=>Validate::text($d,'phone',0,40)]);$l=$this->db->insert('leads',['company_id'=>$company['id'],'contact_id'=>$c['id'],'property_id'=>$p['id'],'source'=>'website','notes'=>Validate::text($d,'notes',0,5000).' · Autorização de contacto registada em '.gmdate('c')]);foreach($this->db->all("SELECT user_id FROM memberships WHERE company_id=? AND role IN('ADMIN','MANAGER') AND active=1",[$company['id']])as $u)(new Operations($this->db))->notify($company['id'],$u['user_id'],'Novo pedido de visita',$c['name'].' pediu informações.','lead:'.$l['id'].':'.$u['user_id']);return ['received'=>true];});
    }
}
