<?php
declare(strict_types=1);
namespace HomeAlone;
final class Scope {
    public function __construct(public Database $db,public string $companyId,public array $user,public string $role) {}
    public function requireRoles(string ...$roles): void { if(!in_array($this->role,$roles,true))throw new HttpError(403,'Não tem permissão para esta operação.'); }
    public function staff(): bool { return in_array($this->role,['ADMIN','MANAGER','FINANCE'],true); }
    public function row(string $table,string $id): array {
        $r=$this->db->one("SELECT * FROM $table WHERE company_id=? AND id=?",[$this->companyId,$id]);if(!$r)throw new HttpError(404,'Registo não encontrado.');return $r;
    }
    public function property(string $id): array {
        $p=$this->row('properties',$id);if($this->staff())return $p;
        if($this->role==='OWNER'){ $owner=$p['owner_id'];$parentId=$p['parent_id'];for($i=0;!$owner&&$parentId&&$i<3;$i++){$parent=$this->row('properties',$parentId);$owner=$parent['owner_id'];$parentId=$parent['parent_id'];}if($owner===$this->user['id'])return $p; }
        if($this->role==='TECHNICIAN'&&$this->db->one('SELECT id FROM tickets WHERE company_id=? AND property_id=? AND assignee_id=?',[$this->companyId,$id,$this->user['id']]))return $p;
        if($this->role==='RESIDENT'){
            $found=$this->db->one("SELECT c.id FROM contracts c JOIN contract_residents cr ON cr.contract_id=c.id JOIN contacts co ON co.id=cr.contact_id WHERE c.company_id=? AND co.user_id=? AND c.status='ACTIVE' AND c.start_date<=business_today() AND (c.end_date IS NULL OR c.end_date>=business_today()) AND (c.property_id=? OR c.property_id IN (SELECT id FROM properties WHERE parent_id=?) OR (?='COMMON' AND (c.property_id=? OR c.property_id IN (SELECT id FROM properties WHERE parent_id=?))))",[$this->companyId,$this->user['id'],$id,$id,$p['type'],$p['parent_id'],$p['parent_id']]);
            if($found)return $p;
        }
        throw new HttpError(404,'Registo não encontrado.');
    }
    public function contract(string $id): array {
        $c=$this->row('contracts',$id);if($this->staff())return $c;
        if($this->role==='OWNER'){ $this->property($c['property_id']);return $c; }
        if($this->role==='RESIDENT'&&$this->db->one('SELECT cr.contract_id FROM contract_residents cr JOIN contacts co ON co.id=cr.contact_id WHERE cr.contract_id=? AND cr.company_id=? AND co.user_id=?',[$id,$this->companyId,$this->user['id']]))return $c;
        throw new HttpError(404,'Registo não encontrado.');
    }
    public function charge(string $id): array { $c=$this->row('charges',$id);$this->contract($c['contract_id']);return $c; }
    public function ticket(string $id): array {
        $t=$this->row('tickets',$id);if($this->staff())return $t;
        if($this->role==='TECHNICIAN'&&$t['assignee_id']===$this->user['id'])return $t;
        if($this->role==='RESIDENT'&&$t['reporter_id']===$this->user['id'])return $t;
        if($this->role==='OWNER'){$this->property($t['property_id']);return $t;}
        throw new HttpError(404,'Registo não encontrado.');
    }
    public function conversation(string $id): array {
        $c=$this->row('conversations',$id);
        if(!$this->db->one('SELECT user_id FROM participants WHERE company_id=? AND conversation_id=? AND user_id=?',[$this->companyId,$id,$this->user['id']]))throw new HttpError(404,'Conversa não encontrada.');return $c;
    }
    public function member(string $id,array $roles=[]): array {
        $m=$this->db->one('SELECT m.*,u.name,u.email FROM memberships m JOIN users u ON u.id=m.user_id WHERE company_id=? AND user_id=? AND active=1',[$this->companyId,$id]);
        if(!$m||($roles&&!in_array($m['role'],$roles,true)))throw new HttpError(422,'Utilizador não pertence à equipa indicada.');return $m;
    }
    public function audit(string $action,string $id): void {$this->db->insert('audit',['company_id'=>$this->companyId,'user_id'=>$this->user['id'],'action'=>$action,'entity_id'=>$id]);}
    public function allowed(callable $fn): bool {try{$fn();return true;}catch(HttpError $e){if($e->status===404)return false;throw $e;}}
}
