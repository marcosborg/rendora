<?php
declare(strict_types=1);
namespace HomeAlone;
final class Operations {
    public function __construct(private Database $db) {}
    public function tickets(Scope $s): array {
        $rows=$this->db->all('SELECT t.*,p.name property_name,u.name reporter_name,a.name assignee_name FROM tickets t JOIN properties p ON p.id=t.property_id JOIN users u ON u.id=t.reporter_id LEFT JOIN users a ON a.id=t.assignee_id WHERE t.company_id=? ORDER BY t.updated_at DESC',[$s->companyId]);
        return array_values(array_filter($rows,fn($t)=>$s->allowed(fn()=>$s->ticket($t['id']))));
    }
    public function ticket(Scope $s,string $id): array {
        $t=$s->ticket($id);$internal=$s->staff()||$s->role==='TECHNICIAN';$t['notes']=$this->db->all('SELECT n.*,u.name author_name FROM ticket_notes n JOIN users u ON u.id=n.author_id WHERE n.company_id=? AND n.ticket_id=? AND (n.internal=0 OR (? + 0)=1) ORDER BY n.created_at',[$s->companyId,$id,$internal?1:0]);return $t;
    }
    public function createTicket(Scope $s,array $d): array {
        $s->requireRoles('ADMIN','MANAGER','RESIDENT','OWNER');$p=$s->property(Validate::text($d,'property_id'));
        return $this->db->transaction(function()use($s,$d,$p){$t=$this->db->insert('tickets',['company_id'=>$s->companyId,'property_id'=>$p['id'],'reporter_id'=>$s->user['id'],'title'=>Validate::text($d,'title',3,200),'description'=>Validate::text($d,'description',5,10000),'category'=>Validate::choice($d,'category',['PLUMBING','ELECTRICITY','APPLIANCE','CLEANING','BUILDING','OTHER']),'priority'=>Validate::choice($d,'priority',['LOW','NORMAL','HIGH','URGENT'])]);$s->audit('ticket.created',$t['id']);foreach($this->db->all("SELECT user_id FROM memberships WHERE company_id=? AND role IN ('ADMIN','MANAGER') AND active=1",[$s->companyId]) as $m)$this->notify($s->companyId,$m['user_id'],'Novo pedido de manutenção',$t['title'],'ticket:'.$t['id'].':'.$m['user_id']);return $t;});
    }
    public function updateTicket(Scope $s,string $id,array $d): array {
        $t=$s->ticket($id);$s->requireRoles('ADMIN','MANAGER','TECHNICIAN');$data=[];
        if(isset($d['status'])){$data['status']=Validate::choice($d,'status',['NEW','TRIAGE','SCHEDULED','IN_PROGRESS','WAITING','RESOLVED','CLOSED']);if(in_array($data['status'],['RESOLVED','CLOSED'])&&empty($d['note']))throw new HttpError(422,'Descreva a resolução do pedido.');}
        if(isset($d['priority']))$data['priority']=Validate::choice($d,'priority',['LOW','NORMAL','HIGH','URGENT']);
        if($s->role!=='TECHNICIAN'){
            foreach(['assignee_id','provider_id'] as $key)if(array_key_exists($key,$d)){$data[$key]=$d[$key]?:null;if($data[$key]){if($key==='assignee_id')$s->member($data[$key],['ADMIN','MANAGER','TECHNICIAN']);else $s->row('contacts',$data[$key]);}}
            foreach(['due_at','scheduled_at'] as $key)if(array_key_exists($key,$d))$data[$key]=Validate::date($d,$key,true);
            if(isset($d['budget_cents'])){$data['budget_cents']=Validate::integer($d,'budget_cents');$data['budget_approved']=0;}
            if(isset($d['budget_approved']))$data['budget_approved']=$d['budget_approved']?1:0;
        }
        if(isset($d['final_cost_cents']))$data['final_cost_cents']=Validate::integer($d,'final_cost_cents');
        return $this->db->transaction(function()use($s,$id,$d,$t,$data){$data['updated_at']=gmdate('Y-m-d H:i:s');(new Rentals($this->db))->update('tickets',$s,$id,$data);if(!empty($d['note']))$this->note($s,$id,['body'=>$d['note'],'internal'=>false]);$s->audit('ticket.updated',$id);$this->notify($s->companyId,$t['reporter_id'],'Atualização do seu pedido',$t['title'].' · '.($data['status']??$t['status']),'ticket-update:'.$id.':'.Database::id());return $s->ticket($id);});
    }
    public function note(Scope $s,string $id,array $d): array {
        $s->ticket($id);$internal=!empty($d['internal']);if($internal&&!$s->staff()&&$s->role!=='TECHNICIAN')throw new HttpError(403,'Não pode publicar notas internas.');return $this->db->insert('ticket_notes',['company_id'=>$s->companyId,'ticket_id'=>$id,'author_id'=>$s->user['id'],'body'=>Validate::text($d,'body',1,10000),'internal'=>$internal?1:0]);
    }
    public function reopen(Scope $s,string $id): array {$t=$s->ticket($id);if(!in_array($t['status'],['RESOLVED','CLOSED']))throw new HttpError(409,'O pedido ainda está aberto.');$this->db->run("UPDATE tickets SET status='TRIAGE',updated_at=CURRENT_TIMESTAMP WHERE id=? AND company_id=?",[$id,$s->companyId]);$s->audit('ticket.reopened',$id);return $s->ticket($id);}
    public function leads(Scope $s): array {$s->requireRoles('ADMIN','MANAGER');return $this->db->all('SELECT l.*,c.name,c.email,c.phone,p.name property_name FROM leads l JOIN contacts c ON c.id=l.contact_id LEFT JOIN properties p ON p.id=l.property_id WHERE l.company_id=? ORDER BY l.created_at DESC',[$s->companyId]);}
    public function createLead(Scope $s,array $d): array {$s->requireRoles('ADMIN','MANAGER');$contact=$s->row('contacts',Validate::text($d,'contact_id'));$property=$d['property_id']??null;if($property)$s->row('properties',$property);return $this->db->insert('leads',['company_id'=>$s->companyId,'contact_id'=>$contact['id'],'property_id'=>$property?:null,'notes'=>Validate::text($d,'notes',0,5000)]);}
    public function updateLead(Scope $s,string $id,array $d): array {$s->requireRoles('ADMIN','MANAGER');$s->row('leads',$id);$data=[];if(isset($d['stage']))$data['stage']=Validate::choice($d,'stage',['NEW','QUALIFIED','VISIT','PROPOSAL','LOST']);foreach(['notes','task'] as $key)if(isset($d[$key]))$data[$key]=Validate::text($d,$key,0,5000);foreach(['visit_at','task_due'] as $key)if(array_key_exists($key,$d))$data[$key]=Validate::date($d,$key,true);(new Rentals($this->db))->update('leads',$s,$id,$data);return $s->row('leads',$id);}
    public function convert(Scope $s,string $id,array $d): array {$s->requireRoles('ADMIN','MANAGER');return $this->db->transaction(function()use($s,$id,$d){$l=$s->row('leads',$id);if($l['stage']==='CONVERTED')throw new HttpError(409,'Oportunidade já convertida.');$contract=(new Rentals($this->db))->createContract($s,[...$d,'contact_ids'=>[$l['contact_id']],'property_id'=>$d['property_id']??$l['property_id']]);$this->db->run("UPDATE leads SET stage='CONVERTED' WHERE id=? AND company_id=?",[$id,$s->companyId]);$s->audit('lead.converted',$id);return $contract;});}
    public function conversations(Scope $s): array {return $this->db->all('SELECT c.*,(SELECT body FROM messages WHERE conversation_id=c.id ORDER BY created_at DESC LIMIT 1) last_message,(SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.author_id<>? AND NOT EXISTS(SELECT 1 FROM message_reads r WHERE r.message_id=m.id AND r.user_id=?)) unread FROM conversations c JOIN participants p ON p.conversation_id=c.id WHERE c.company_id=? AND p.user_id=? ORDER BY c.created_at DESC',[$s->user['id'],$s->user['id'],$s->companyId,$s->user['id']]);}
    public function createConversation(Scope $s,array $d): array {
        $ids=Validate::strings($d,'participant_ids',50);if(!$ids)throw new HttpError(422,'Selecione um destinatário.');$property=$d['property_id']??null;if($property)$s->property($property);
        foreach($ids as $id){$member=$s->member($id);if(!$s->staff()&&!in_array($member['role'],['ADMIN','MANAGER'])){$allowed=false;if($property){$p=$s->property($property);if($s->role==='RESIDENT'&&$member['role']==='OWNER'&&$p['owner_id']===$id)$allowed=true;if($s->role==='OWNER'&&$member['role']==='RESIDENT')$allowed=(bool)$this->db->one("SELECT c.id FROM contracts c JOIN contract_residents cr ON cr.contract_id=c.id JOIN contacts co ON co.id=cr.contact_id WHERE c.company_id=? AND c.property_id=? AND co.user_id=? AND c.status='ACTIVE' AND c.start_date<=business_today() AND COALESCE(c.end_date,'9999-12-31')>=business_today()",[$s->companyId,$property,$id]);}if(!$allowed)throw new HttpError(403,'Associe um alojamento partilhado com este destinatário.');}}
        return $this->db->transaction(function()use($s,$d,$ids,$property){$c=$this->db->insert('conversations',['company_id'=>$s->companyId,'subject'=>Validate::text($d,'subject',2,200),'property_id'=>$property?:null]);foreach(array_unique([...$ids,$s->user['id']]) as $id)$this->db->run('INSERT INTO participants(company_id,conversation_id,user_id) VALUES(?,?,?)',[$s->companyId,$c['id'],$id]);if(!empty($d['body']))$this->send($s,$c['id'],$d);return $c;});
    }
    public function messages(Scope $s,string $id): array {$s->conversation($id);return $this->db->all('SELECT m.id,m.company_id,m.conversation_id,m.author_id,m.body,m.created_at,u.name author_name,(SELECT COUNT(*) FROM message_reads r WHERE r.message_id=m.id AND r.user_id<>m.author_id) read_count FROM messages m JOIN users u ON u.id=m.author_id WHERE m.company_id=? AND m.conversation_id=? ORDER BY m.created_at,'.($this->db->engine()==='mysql'?'m.message_sequence':'m.rowid'),[$s->companyId,$id]);}
    public function markRead(Scope $s,string $id): void {
        $s->conversation($id);
        $user=$s->user['id'];
        $this->db->transaction(function()use($s,$id,$user){
            $this->db->run('INSERT INTO message_reads(message_id,user_id) SELECT m.id,? FROM messages m WHERE m.company_id=? AND m.conversation_id=? AND NOT EXISTS (SELECT 1 FROM message_reads r WHERE r.message_id=m.id AND r.user_id=?)',[$user,$s->companyId,$id,$user]);
        });
    }
    public function send(Scope $s,string $id,array $d): array {$c=$s->conversation($id);return $this->db->transaction(function()use($s,$id,$d,$c){$m=$this->db->insert('messages',['company_id'=>$s->companyId,'conversation_id'=>$id,'author_id'=>$s->user['id'],'body'=>Validate::text($d,'body',1,10000)]);foreach($this->db->all('SELECT user_id FROM participants WHERE conversation_id=? AND user_id<>?',[$id,$s->user['id']]) as $p)$this->notify($s->companyId,$p['user_id'],'Nova mensagem: '.$c['subject'],'Tem uma nova mensagem no portal.','message:'.$m['id'].':'.$p['user_id']);return $m;});}
    public function notify(string $company,string $user,string $title,string $body,string $key,?string $charge=null): void {
        $this->db->transaction(function()use($company,$user,$title,$body,$key,$charge){if($this->db->one('SELECT id FROM notifications WHERE dedupe_key=?',[$key]))return;$n=$this->db->insert('notifications',['company_id'=>$company,'user_id'=>$user,'title'=>$title,'body'=>$body,'dedupe_key'=>$key,'charge_id'=>$charge]);$this->db->insert('jobs',['company_id'=>$company,'type'=>'NOTIFICATION','payload'=>json_encode(['notification_id'=>$n['id']]),'dedupe_key'=>'email:'.$key]);});
    }
    public function broadcast(Scope $s,array $d): array {
        $s->requireRoles('ADMIN','MANAGER');$title=Validate::text($d,'title',2,200);$body=Validate::text($d,'body',2,10000);$users=Validate::strings($d,'user_ids',200);$key=Validate::text($d,'idempotency_key',8,150);
        if(!empty($d['property_id'])){$p=$s->row('properties',$d['property_id']);$users=array_column($this->db->all("SELECT DISTINCT co.user_id FROM contacts co JOIN contract_residents cr ON cr.contact_id=co.id JOIN contracts c ON c.id=cr.contract_id WHERE c.company_id=? AND c.status='ACTIVE' AND c.start_date<=business_today() AND (c.end_date IS NULL OR c.end_date>=business_today()) AND co.user_id IS NOT NULL AND (c.property_id=? OR c.property_id IN (SELECT id FROM properties WHERE parent_id=?) OR c.property_id IN (SELECT child.id FROM properties child JOIN properties parent ON parent.id=child.parent_id WHERE parent.parent_id=?))",[$s->companyId,$p['id'],$p['id'],$p['id']]),'user_id');}
        foreach($users as $user)$s->member($user);if(!$users)throw new HttpError(422,'Não existem destinatários com acesso ao portal.');foreach($users as $user)$this->notify($s->companyId,$user,$title,$body,'broadcast:'.$s->companyId.':'.$key.':'.$user);$s->audit('broadcast.sent',$key);return ['recipients'=>count($users)];
    }
}
