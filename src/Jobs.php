<?php
declare(strict_types=1);
namespace HomeAlone;
final class Jobs {
    public function __construct(private Database $db) {}
    public function generateMonthly(?string $today=null): int {
        $today??=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Lisbon')))->format('Y-m-d');$first=new \DateTimeImmutable(substr($today,0,7).'-01');$periods=[$first->format('Y-m')];
        if((int)substr($today,8,2)>=23)$periods[]=$first->modify('+1 month')->format('Y-m');$count=0;$this->db->run("UPDATE contracts SET status='ENDED' WHERE status='ACTIVE' AND end_date<business_today()");
        foreach($this->db->all("SELECT c.id,(SELECT m.user_id FROM memberships m WHERE m.company_id=c.id AND m.role='ADMIN' AND m.active=1 LIMIT 1) admin_id FROM companies c") as $company){if(!$company['admin_id'])continue;$s=new Scope($this->db,$company['id'],['id'=>$company['admin_id']],'ADMIN');foreach($periods as $period)$count+=(new Finance($this->db))->generate($s,$period)['created'];}return $count;
    }
    public function reminders(?string $today=null): int {
        $today??=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Lisbon')))->format('Y-m-d');$ops=new Operations($this->db);$count=0;
        foreach($this->db->all('SELECT ch.*,c.reminder_days,c.email_template FROM charges ch JOIN companies c ON c.id=ch.company_id WHERE ch.confirmed=1') as $ch){$offset=(int)(new \DateTimeImmutable($ch['due_date']))->diff(new \DateTimeImmutable($today))->format('%r%a');if(!in_array($offset,json_decode($ch['reminder_days'],true),true))continue;$balance=(new Finance($this->db))->balance($ch['id']);if($balance['balance_cents']<=0)continue;
            foreach($this->db->all('SELECT DISTINCT co.user_id FROM contacts co JOIN contract_residents cr ON cr.contact_id=co.id JOIN memberships m ON m.user_id=co.user_id AND m.company_id=cr.company_id WHERE cr.contract_id=? AND co.user_id IS NOT NULL AND m.active=1',[$ch['contract_id']]) as $u){$key='rent:'.$ch['id'].':'.$offset.':'.$u['user_id'];$ops->notify($ch['company_id'],$u['user_id'],$offset>0?'Renda por regularizar':'Lembrete de renda',strtr($ch['email_template'],['{{date}}'=>date('d/m/Y',strtotime($ch['due_date'])),'{{amount}}'=>number_format($balance['balance_cents']/100,2,',','.').' €']),$key,$ch['id'],[
                $offset>0?'Outstanding rent':'Rent reminder',
                'The outstanding rent balance is '.Language::money($balance['balance_cents'],true).', due on '.date('d/m/Y',strtotime($ch['due_date'])).'. Open the portal for details.',
            ]);$count++;}}
        foreach($this->db->all("SELECT c.*,p.name property_name FROM contracts c JOIN properties p ON p.id=c.property_id WHERE c.status='ACTIVE' AND c.end_date IN (?,?)",[date('Y-m-d',strtotime($today.' +30 days')),date('Y-m-d',strtotime($today.' +7 days'))]) as $c)foreach($this->db->all("SELECT user_id FROM memberships WHERE company_id=? AND role IN ('ADMIN','MANAGER') AND active=1",[$c['company_id']]) as $m)$ops->notify($c['company_id'],$m['user_id'],'Contrato a terminar',$c['property_name'].' · '.$c['end_date'],'expiry:'.$c['id'].':'.$today.':'.$m['user_id'],englishEmail:['Rental agreement ending','The rental agreement for '.$c['property_name'].' ends on '.$c['end_date'].'.']);
        return $count;
    }
    public function work(int $limit=30,?callable $sender=null): array {
        $done=0;$failed=0;$this->db->run('DELETE FROM sessions WHERE expires_at<CURRENT_TIMESTAMP');$this->db->run('DELETE FROM reset_tokens WHERE expires_at<CURRENT_TIMESTAMP');$this->db->run('DELETE FROM rate_limits WHERE expires_at<?',[time()]);$this->db->run("UPDATE jobs SET status='PENDING',locked_at=NULL WHERE status='PROCESSING' AND locked_at<?",[gmdate('Y-m-d H:i:s',time()-600)]);
        for($i=0;$i<$limit;$i++){$job=$this->db->transaction(function(){ $j=$this->db->one("SELECT * FROM jobs WHERE status='PENDING' AND available_at<=CURRENT_TIMESTAMP ORDER BY created_at LIMIT 1");if(!$j)return null;$this->db->run("UPDATE jobs SET status='PROCESSING',attempts=attempts+1,locked_at=CURRENT_TIMESTAMP WHERE id=?",[$j['id']]);return $j;});if(!$job)break;
            try{$payload=json_decode($job['payload'],true);$notification=null;
                if($job['type']==='NOTIFICATION'){$notification=$this->db->one('SELECT n.*,u.email FROM notifications n JOIN users u ON u.id=n.user_id JOIN memberships m ON m.user_id=n.user_id AND m.company_id=n.company_id AND m.active=1 WHERE n.id=?',[$payload['notification_id']]);if(!$notification||($notification['charge_id']&&(new Finance($this->db))->balance($notification['charge_id'])['balance_cents']<=0)){$this->db->run("UPDATE jobs SET status='CANCELLED' WHERE id=?",[$job['id']]);if($notification)$this->db->run("UPDATE notifications SET email_status='CANCELLED' WHERE id=?",[$notification['id']]);continue;}$english=$payload['english']??null;
                    $payload=['email'=>$notification['email'],'subject'=>$notification['title'],'body'=>$notification['body']];
                    if($english){
                        $payload['subject'].=' / '.$english[0];
                        $payload['body'].="\n\nEnglish\n".$english[1];
                    }}
                ($sender??[$this,'send'])($payload,$job['dedupe_key']);
                $this->db->transaction(function()use($job,$notification){$this->db->run("UPDATE jobs SET status='DONE',last_error=NULL WHERE id=?",[$job['id']]);if($notification)$this->db->run("UPDATE notifications SET email_status='SENT' WHERE id=?",[$notification['id']]);});$done++;
            }catch(\Throwable $e){$attempts=$job['attempts']+1;$status=$attempts>=5?'FAILED':'PENDING';$this->db->run('UPDATE jobs SET status=?,available_at=?,last_error=? WHERE id=?',[$status,gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**$attempts))),$e instanceof HttpError?$e->getMessage():'Falha temporária no envio.',$job['id']]);if($notification)$this->db->run("UPDATE notifications SET email_status=? WHERE id=?",[$status==='FAILED'?'FAILED':'PENDING',$notification['id']]);$failed++;}
        }return ['sent'=>$done,'failed'=>$failed];
    }
    public function send(array $d,string $key): void {
        if(env('DEMO_MODE')==='true')throw new HttpError(503,'Emails externos desativados na demonstração.');
        if(!MailTransport::configured())throw new HttpError(503,'Serviço de email não configurado.');
        if(env('SMTP_HOST')!==''){
            MailTransport::smtp($d,$key);
            return;
        }
        $ch=curl_init('https://api.resend.com/emails');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.env('RESEND_API_KEY'),'Content-Type: application/json','Idempotency-Key: '.hash('sha256',$key)],CURLOPT_POSTFIELDS=>json_encode(['from'=>env('EMAIL_FROM'),'to'=>[$d['email']],'subject'=>$d['subject'],'text'=>$d['body']])]);curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($status<200||$status>=300)throw new HttpError(502,'O serviço de email não aceitou o envio.');
    }
}
