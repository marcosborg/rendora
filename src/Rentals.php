<?php
declare(strict_types=1);
namespace HomeAlone;
final class Rentals {
    public function __construct(private Database $db) {}
    public function properties(Scope $s): array {
        $rows=$this->db->all("SELECT p.*,u.name owner_name FROM properties p LEFT JOIN users u ON u.id=p.owner_id WHERE p.company_id=? ORDER BY p.created_at DESC,p.name",[$s->companyId]);
        return array_values(array_map(function($p){$p['photos']=json_decode($p['photos'],true);$p['amenities']=json_decode($p['amenities'],true);$p['occupied']=(bool)$this->db->one("SELECT id FROM contracts WHERE company_id=? AND status='ACTIVE' AND start_date<=business_today() AND (end_date IS NULL OR end_date>=business_today()) AND (property_id=? OR property_id IN(SELECT id FROM properties WHERE parent_id=?) OR property_id=?)",[$p['company_id'],$p['id'],$p['id'],$p['parent_id']]);return $p;},array_filter($rows,fn($p)=>$s->allowed(fn()=>$s->property($p['id'])))));
    }
    public function saveProperty(Scope $s,array $d,?string $id=null): array {
        $s->requireRoles('ADMIN','MANAGER');if($id)$s->row('properties',$id);
        $data=['name'=>Validate::text($d,'name',2,150),'type'=>Validate::choice($d,'type',['BUILDING','APARTMENT','ROOM','COMMON']),'address'=>Validate::text($d,'address',2,250),'city'=>Validate::text($d,'city',2,100),'description'=>Validate::text($d,'description',0,10000),'rent_cents'=>Validate::integer($d,'rent_cents'),'bedrooms'=>Validate::integer($d,'bedrooms',0,100),'area'=>Validate::integer($d,'area',0,100000),'published'=>!empty($d['published'])?1:0,'parent_id'=>($d['parent_id']??null)?:null,'owner_id'=>($d['owner_id']??null)?:null];
        $photos=Validate::strings($d,'photos',20);foreach($photos as $url){if(preg_match('~^/api/v1/public/photos/([a-f0-9]{24})$~',$url,$match)){if(!$id||!$this->db->one("SELECT id FROM documents WHERE id=? AND company_id=? AND entity_type='property' AND entity_id=?",[$match[1],$s->companyId,$id]))throw new HttpError(422,'Fotografia inválida.');}elseif(!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https')throw new HttpError(422,'As fotografias devem usar endereços HTTPS.');}
        $data['photos']=json_encode($photos);$data['amenities']=json_encode(Validate::strings($d,'amenities'));
        if($data['owner_id'])$s->member($data['owner_id'],['OWNER']);
        if($data['parent_id']){ $parent=$s->row('properties',$data['parent_id']);$expected=$data['type']==='ROOM'?'APARTMENT':'BUILDING';if($parent['type']!==$expected||$parent['id']===$id)throw new HttpError(422,'Hierarquia de imóveis inválida.'); }
        if($data['type']==='ROOM'&&!$data['parent_id'])throw new HttpError(422,'Um quarto deve pertencer a um apartamento.');
        return $this->db->transaction(function()use($s,$id,$data){
            if(!$id){$c=$this->db->one('SELECT p.property_limit FROM companies c JOIN plans p ON p.id=c.plan_id WHERE c.id=?',[$s->companyId]);$count=$this->db->one('SELECT COUNT(*) n FROM properties WHERE company_id=?',[$s->companyId]);if($c&&$count['n']>=$c['property_limit'])throw new HttpError(409,'Limite de imóveis do plano atingido.');$p=$this->db->insert('properties',['company_id'=>$s->companyId,...$data]);}
            else{ $old=$s->row('properties',$id);if(($old['type']!==$data['type']||$old['parent_id']!==$data['parent_id'])&&($this->db->one('SELECT id FROM contracts WHERE property_id=?',[$id])||$this->db->one('SELECT id FROM properties WHERE parent_id=?',[$id])))throw new HttpError(409,'Não pode alterar a hierarquia de um imóvel com contratos ou dependências.');$this->update('properties',$s,$id,$data);$p=$s->row('properties',$id);}
            $s->audit('property.saved',$p['id']);return $p;
        });
    }
    public function contacts(Scope $s): array {$s->requireRoles('ADMIN','MANAGER','FINANCE');return $this->db->all('SELECT * FROM contacts WHERE company_id=? ORDER BY name',[$s->companyId]);}
    public function saveContact(Scope $s,array $d,?string $id=null): array {
        $s->requireRoles('ADMIN','MANAGER');$data=['name'=>Validate::text($d,'name',2,150),'email'=>Validate::email($d),'phone'=>Validate::text($d,'phone',0,40),'kind'=>Validate::choice($d,'kind',['LEAD','RESIDENT','OWNER','PROVIDER']),'notes'=>Validate::text($d,'notes',0,10000),'tags'=>json_encode(Validate::strings($d,'tags'))];
        $linked=$this->db->one('SELECT u.id FROM users u JOIN memberships m ON m.user_id=u.id WHERE m.company_id=? AND u.email=?',[$s->companyId,$data['email']]);$data['user_id']=$linked['id']??null;if(!empty($d['user_id'])){$m=$s->member($d['user_id']);if(strtolower($m['email'])!==$data['email'])throw new HttpError(422,'O email deve corresponder ao utilizador associado.');$data['user_id']=$d['user_id'];}
        if($id){$s->row('contacts',$id);$this->update('contacts',$s,$id,$data);return $s->row('contacts',$id);}return $this->db->insert('contacts',['company_id'=>$s->companyId,...$data]);
    }
    public function contracts(Scope $s): array {
        return array_values(array_map(function($c){$c['residents']=$this->db->all('SELECT co.id,co.name,co.email,co.user_id FROM contract_residents cr JOIN contacts co ON co.id=cr.contact_id WHERE cr.contract_id=?',[$c['id']]);return $c;},array_filter($this->db->all('SELECT c.*,p.name property_name FROM contracts c JOIN properties p ON p.id=c.property_id WHERE c.company_id=? ORDER BY c.start_date DESC',[$s->companyId]),fn($c)=>$s->allowed(fn()=>$s->contract($c['id'])))));
    }
    public function createContract(Scope $s,array $d): array {
        $s->requireRoles('ADMIN','MANAGER');$property=$s->row('properties',Validate::text($d,'property_id'));
        if(!in_array($property['type'],['APARTMENT','ROOM']))throw new HttpError(422,'Só apartamentos e quartos podem ser arrendados.');
        $start=Validate::date($d,'start_date');$end=Validate::date($d,'end_date',true);if($end&&$end<$start)throw new HttpError(422,'A data final deve ser posterior ao início.');
        $contacts=Validate::strings($d,'contact_ids',20);if(!$contacts)throw new HttpError(422,'Selecione pelo menos um morador.');foreach($contacts as $id)$s->row('contacts',$id);
        $data=['company_id'=>$s->companyId,'property_id'=>$property['id'],'start_date'=>$start,'end_date'=>$end,'rent_cents'=>Validate::integer($d,'rent_cents',1),'charges_cents'=>Validate::integer($d,'charges_cents'),'deposit_cents'=>Validate::integer($d,'deposit_cents'),'due_day'=>Validate::integer($d,'due_day',1,31),'notes'=>Validate::text($d,'notes',0,10000)];
        if(isset($d['template']))$data['template']=Validate::text($d,'template',10,30000);
        return $this->db->transaction(function()use($s,$data,$contacts,$property,$start,$end){
            $this->assertNoOverlap($s->companyId,$property,$start,$end);
            $c=$this->db->insert('contracts',$data);foreach($contacts as $id){$this->db->run('INSERT INTO contract_residents(company_id,contract_id,contact_id) VALUES(?,?,?)',[$s->companyId,$c['id'],$id]);$this->db->run("UPDATE contacts SET kind='RESIDENT',user_id=(SELECT u.id FROM users u JOIN memberships m ON m.user_id=u.id WHERE u.email=contacts.email AND m.company_id=contacts.company_id LIMIT 1) WHERE id=? AND company_id=?",[$id,$s->companyId]);}
            $s->audit('contract.created',$c['id']);return $s->row('contracts',$c['id']);
        });
    }
    private function assertNoOverlap(string $company,array $p,string $start,?string $end,?string $ignore=null): void {
        $conflict=$this->db->one("SELECT c.id FROM contracts c WHERE c.company_id=? AND c.status<>'CANCELLED' AND c.start_date<=? AND COALESCE(c.end_date,'9999-12-31')>=? AND c.id<>? AND (c.property_id=? OR c.property_id IN(SELECT id FROM properties WHERE parent_id=?) OR c.property_id=?)",[$company,$end??'9999-12-31',$start,$ignore??'',$p['id'],$p['id'],$p['parent_id']]);
        if($conflict)throw new HttpError(409,'Existe um contrato sobreposto para este espaço ou para o apartamento que o contém.');
    }
    public function changeContract(Scope $s,string $id,array $d): array {
        $s->requireRoles('ADMIN','MANAGER');$c=$s->contract($id);
        return $this->db->transaction(function()use($s,$id,$d,$c){
            $action=Validate::choice($d,'action',['renew','end','rent','template']);
            if($action==='template')$this->update('contracts',$s,$id,['template'=>Validate::text($d,'template',10,30000)]);
            elseif($action==='rent'){
                $date=Validate::date($d,'effective_date');if($date<$c['start_date']||($c['end_date']&&$date>$c['end_date']))throw new HttpError(422,'A alteração deve estar dentro do contrato.');
                if($date<Database::today())throw new HttpError(422,'As alterações de renda devem ter efeitos no futuro.');
                $affected=$this->db->all('SELECT * FROM charges WHERE contract_id=? AND period>=?',[$id,substr($date,0,7)]);
                foreach($affected as $ch)if($this->db->one('SELECT id FROM payments WHERE charge_id=?',[$ch['id']])||$this->db->one("SELECT id FROM checkouts WHERE charge_id=? AND status IN('CREATING','OPEN','PROCESSING','SUCCEEDED')",[$ch['id']]))throw new HttpError(409,'Existem pagamentos ou operações em curso a partir desta data. Reconcilie os movimentos antes de alterar a renda.');
                $this->db->run('INSERT INTO rent_changes(id,company_id,contract_id,effective_date,rent_cents) VALUES(?,?,?,?,?) ON CONFLICT(contract_id,effective_date) DO UPDATE SET rent_cents=excluded.rent_cents',[Database::id(),$s->companyId,$id,$date,Validate::integer($d,'rent_cents',1)]);
                foreach($affected as $ch){$calc=Finance::monthlyAmount($c,$ch['period'],$this->db->all('SELECT * FROM rent_changes WHERE contract_id=?',[$id]));$this->db->run('UPDATE charges SET amount_cents=?,confirmed=? WHERE id=?',[$calc['amount_cents'],$calc['confirmed']?1:0,$ch['id']]);}
            }else{
                $end=Validate::date($d,'end_date');if($end<$c['start_date'])throw new HttpError(422,'Data de fim inválida.');
                if($action==='renew'&&$c['end_date']&&$end<=$c['end_date'])throw new HttpError(422,'A renovação deve prolongar o contrato.');
                if($action==='end'&&$this->db->one('SELECT id FROM charges WHERE contract_id=? AND period>=?',[$id,substr($end,0,7)])&&empty($d['acknowledge_charges']))throw new HttpError(409,'Existem cobranças no mês de saída ou posteriores. Confirme que as vai reconciliar por ajustes e reembolsos; o histórico será preservado.');
                $this->assertNoOverlap($s->companyId,$s->row('properties',$c['property_id']),$c['start_date'],$end,$id);
                $this->update('contracts',$s,$id,['end_date'=>$end,'status'=>$end<Database::today()?'ENDED':'ACTIVE']);
            }$s->audit('contract.'.$action,$id);return $s->contract($id);
        });
    }
    public function contractDetail(Scope $s,string $id): array {
        $c=$s->contract($id);$c['residents']=$this->db->all('SELECT co.id,co.name,co.email FROM contacts co JOIN contract_residents cr ON cr.contact_id=co.id WHERE cr.contract_id=?',[$id]);
        foreach(['rent_changes','deposit_entries','inspections','charges'] as $table)$c[$table]=$this->db->all("SELECT * FROM $table WHERE company_id=? AND contract_id=?",[$s->companyId,$id]);return $c;
    }
    public function deposit(Scope $s,string $id,array $d): array {
        $s->requireRoles('ADMIN','MANAGER','FINANCE');$s->contract($id);$kind=Validate::choice($d,'kind',['RECEIVED','RETURNED','DEDUCTED']);$amount=Validate::integer($d,'amount_cents',1);$reason=Validate::text($d,'reason',3,1000);
        return $this->db->transaction(function()use($s,$id,$kind,$amount,$reason){$balance=(int)$this->db->one("SELECT COALESCE(SUM(CASE WHEN kind='RECEIVED' THEN amount_cents ELSE -amount_cents END),0) balance FROM deposit_entries WHERE contract_id=?",[$id])['balance'];if($kind!=='RECEIVED'&&$amount>$balance)throw new HttpError(422,'A operação excede o saldo da caução.');$r=$this->db->insert('deposit_entries',['company_id'=>$s->companyId,'contract_id'=>$id,'kind'=>$kind,'amount_cents'=>$amount,'reason'=>$reason]);$s->audit('deposit.'.$kind,$r['id']);return $r;});
    }
    public function inspection(Scope $s,string $id,array $d): array {
        $s->requireRoles('ADMIN','MANAGER');$s->contract($id);return $this->db->insert('inspections',['company_id'=>$s->companyId,'contract_id'=>$id,'kind'=>Validate::choice($d,'kind',['ENTRY','EXIT']),'notes'=>Validate::text($d,'notes',0,10000),'items'=>json_encode(Validate::strings($d,'items',100))]);
    }
    public function update(string $table,Scope $s,string $id,array $d): void {
        if(!$d)return;$set=implode(',',array_map(fn($key)=>"$key=?",array_keys($d)));$this->db->run("UPDATE $table SET $set WHERE company_id=? AND id=?",[...array_values($d),$s->companyId,$id]);
    }
}
