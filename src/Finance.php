<?php
declare(strict_types=1);
namespace HomeAlone;
final class Finance {
    public function __construct(private Database $db) {}
    public static function monthlyAmount(array $contract,string $period,array $changes=[]): array {
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period))throw new HttpError(422,'Mês inválido.');
        $first=new \DateTimeImmutable($period.'-01');$days=(int)$first->format('t');$last=$first->modify('last day of this month')->format('Y-m-d');
        $from=max($contract['start_date'],$period.'-01');$to=min($contract['end_date']??$last,$last);if($from>$to)return ['amount_cents'=>0,'confirmed'=>true];
        usort($changes,fn($a,$b)=>strcmp($a['effective_date'],$b['effective_date']));$sum=0;
        for($date=new \DateTimeImmutable($from);$date->format('Y-m-d')<=$to;$date=$date->modify('+1 day')){ $rent=$contract['rent_cents'];foreach($changes as $change)if($change['effective_date']<=$date->format('Y-m-d'))$rent=$change['rent_cents'];$sum+=$rent+$contract['charges_cents']; }
        return ['amount_cents'=>(int)round($sum/$days),'confirmed'=>$from===$period.'-01'&&$to===$last,'due_date'=>$period.'-'.str_pad((string)min($contract['due_day'],$days),2,'0',STR_PAD_LEFT)];
    }
    public function generate(Scope $s,string $period): array {
        $s->requireRoles('ADMIN','MANAGER','FINANCE');if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period))throw new HttpError(422,'Mês inválido.');
        return $this->db->transaction(function()use($s,$period){$created=0;$drafts=0;
            foreach($this->db->all("SELECT * FROM contracts WHERE company_id=? AND status<>'CANCELLED'",[$s->companyId]) as $c){if($this->db->one('SELECT id FROM charges WHERE contract_id=? AND period=?',[$c['id'],$period]))continue;
                $calc=self::monthlyAmount($c,$period,$this->db->all('SELECT * FROM rent_changes WHERE contract_id=?',[$c['id']]));if(!$calc['amount_cents'])continue;
                $this->db->insert('charges',['company_id'=>$s->companyId,'contract_id'=>$c['id'],'period'=>$period,'due_date'=>$calc['due_date'],'amount_cents'=>$calc['amount_cents'],'confirmed'=>$calc['confirmed']?1:0]);$created++;if(!$calc['confirmed'])$drafts++;
            }if($created)$s->audit('charges.generated',$period);return ['created'=>$created,'drafts'=>$drafts];});
    }
    public function balance(string $id): array {
        $charge=$this->db->one('SELECT * FROM charges WHERE id=?',[$id]);if(!$charge)throw new HttpError(404,'Cobrança não encontrada.');
        $paid=(int)$this->db->one("SELECT COALESCE(SUM(CASE WHEN kind IN('PAYMENT','ADJUSTMENT','DISPUTE_WON') THEN amount_cents ELSE -amount_cents END),0) total FROM payments WHERE charge_id=?",[$id])['total'];
        $state='PENDING';$balance=$charge['amount_cents']-$paid;
        if(!$charge['confirmed'])$state='DRAFT';elseif($balance<=0)$state='PAID';elseif($paid>0)$state='PARTIAL';elseif($this->db->one("SELECT id FROM payments WHERE charge_id=? AND kind='REFUND'",[$id]))$state='REFUNDED';elseif($this->db->one("SELECT id FROM checkouts WHERE charge_id=? AND status IN ('PROCESSING','OPEN','CREATING')",[$id]))$state='PROCESSING';elseif($this->db->one("SELECT id FROM checkouts WHERE charge_id=? AND status='FAILED'",[$id]))$state='FAILED';
        return [...$charge,'paid_cents'=>$paid,'balance_cents'=>$balance,'status'=>$state,'overdue'=>$balance>0&&$charge['confirmed']&&$charge['due_date']<(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Lisbon')))->format('Y-m-d')];
    }
    public function charges(Scope $s,array $filter=[]): array {
        $rows=$this->db->all('SELECT ch.id,p.name property_name,p.id property_id,p.owner_id,c.start_date FROM charges ch JOIN contracts c ON c.id=ch.contract_id JOIN properties p ON p.id=c.property_id WHERE ch.company_id=? ORDER BY ch.due_date DESC',[$s->companyId]);$out=[];
        foreach($rows as $row){if(!$s->allowed(fn()=>$s->charge($row['id'])))continue;$ch=[...$row,...$this->balance($row['id'])];if(!empty($filter['period'])&&$ch['period']!==$filter['period'])continue;if(!empty($filter['property_id'])&&$row['property_id']!==$filter['property_id'])continue;if(!empty($filter['owner_id'])&&$row['owner_id']!==$filter['owner_id'])continue;$ch['residents']=$this->db->all('SELECT co.name FROM contacts co JOIN contract_residents cr ON cr.contact_id=co.id WHERE cr.contract_id=?',[$ch['contract_id']]);$out[]=$ch;}return $out;
    }
    public function manual(Scope $s,string $id,array $d): array {
        $s->requireRoles('ADMIN','FINANCE');$s->charge($id);$amount=Validate::integer($d,'amount_cents',1);$key=Validate::text($d,'idempotency_key',8,150);$reason=Validate::text($d,'reason',3,1000);$kind=Validate::choice($d,'kind',['PAYMENT','ADJUSTMENT']);
        return $this->db->transaction(function()use($s,$id,$amount,$key,$reason,$kind){$key=$s->companyId.':'.$key;$existing=$this->db->one('SELECT * FROM payments WHERE idempotency_key=?',[$key]);if($existing){if($existing['charge_id']!==$id||$existing['amount_cents']!==$amount||$existing['kind']!==$kind)throw new HttpError(409,'Chave de operação já utilizada.');return $existing;}
            $b=$this->balance($id);if(!$b['confirmed']||$amount>$b['balance_cents'])throw new HttpError(422,'Valor superior ao saldo ou cobrança por confirmar.');
            if($this->db->one("SELECT id FROM checkouts WHERE charge_id=? AND status IN('CREATING','OPEN','PROCESSING')",[$id]))throw new HttpError(409,'Existe um pagamento online em curso. Aguarde a confirmação ou expiração.');
            $p=$this->db->insert('payments',['company_id'=>$s->companyId,'charge_id'=>$id,'amount_cents'=>$amount,'kind'=>$kind,'method'=>'MANUAL','reason'=>$reason,'idempotency_key'=>$key]);$s->audit('payment.manual',$p['id']);return $p;});
    }
    public function confirm(Scope $s,string $id): array {$s->requireRoles('ADMIN','MANAGER','FINANCE');$s->charge($id);$this->db->run('UPDATE charges SET confirmed=1 WHERE id=? AND company_id=?',[$id,$s->companyId]);$s->audit('charge.confirmed',$id);return $this->balance($id);}
    public function expense(Scope $s,array $d): array {$s->requireRoles('ADMIN','MANAGER','FINANCE');$p=$s->row('properties',Validate::text($d,'property_id'));$ticket=$d['ticket_id']??null;if($ticket){$t=$s->ticket($ticket);if($t['property_id']!==$p['id'])throw new HttpError(422,'Pedido e imóvel incompatíveis.');}return $this->db->insert('expenses',['company_id'=>$s->companyId,'property_id'=>$p['id'],'ticket_id'=>$ticket?:null,'description'=>Validate::text($d,'description',3,1000),'amount_cents'=>Validate::integer($d,'amount_cents',1),'date'=>Validate::date($d,'date')]);}
    public function expenses(Scope $s): array {$s->requireRoles('ADMIN','MANAGER','FINANCE','OWNER');return array_values(array_filter($this->db->all('SELECT e.*,p.name property_name FROM expenses e JOIN properties p ON p.id=e.property_id WHERE e.company_id=? ORDER BY date DESC',[$s->companyId]),fn($e)=>$s->allowed(fn()=>$s->property($e['property_id']))));}
    public function ledger(Scope $s,string $id): array {$c=$s->charge($id);return [...$this->balance($id),'movements'=>$this->db->all('SELECT * FROM payments WHERE company_id=? AND charge_id=? ORDER BY created_at',[$s->companyId,$id])];}
    public function dashboard(Scope $s,array $filters=[]): array {
        $rentals=new Rentals($this->db);$props=array_filter($rentals->properties($s),fn($p)=>empty($filters['property_id'])||$p['id']===$filters['property_id']);if(!empty($filters['owner_id']))$props=array_filter($props,fn($p)=>$p['owner_id']===$filters['owner_id']);
        // Count rooms when an apartment is subdivided; never count the parent twice.
        $units=array_filter($props,fn($p)=>$p['type']==='ROOM'||($p['type']==='APARTMENT'&&!$this->db->one("SELECT id FROM properties WHERE parent_id=? AND type='ROOM'",[$p['id']])));
        $charges=$this->charges($s,$filters);$expected=array_sum(array_column(array_filter($charges,fn($c)=>$c['confirmed']),'amount_cents'));$paid=array_sum(array_column($charges,'paid_cents'));$overdue=array_sum(array_map(fn($c)=>$c['overdue']?$c['balance_cents']:0,$charges));
        $occupied=count(array_filter($units,fn($p)=>$p['occupied']));$history=[];
        for($i=5;$i>=0;$i--){$period=(new \DateTimeImmutable(($filters['period']??date('Y-m')).'-01'))->modify("-$i months")->format('Y-m');$rows=$this->charges($s,[...$filters,'period'=>$period]);$history[]=['period'=>$period,'expected'=>array_sum(array_column(array_filter($rows,fn($c)=>$c['confirmed']),'amount_cents')),'received'=>array_sum(array_column($rows,'paid_cents'))];}
        return ['expected_cents'=>$expected,'received_cents'=>$paid,'overdue_cents'=>$overdue,'units'=>count($units),'occupied'=>$occupied,'occupancy'=>count($units)?round($occupied/count($units)*100):0,'history'=>$history,'charges'=>$charges,'expiring'=>array_values(array_filter($rentals->contracts($s),fn($c)=>(empty($filters['property_id'])||$c['property_id']===$filters['property_id'])&&(empty($filters['owner_id'])||$this->db->one('SELECT owner_id FROM properties WHERE id=?',[$c['property_id']])['owner_id']===$filters['owner_id'])&&$c['status']==='ACTIVE'&&$c['end_date']&&$c['end_date']>=Database::today()&&$c['end_date']<=(new \DateTimeImmutable(Database::today()))->modify('+60 days')->format('Y-m-d')))];
    }
}
