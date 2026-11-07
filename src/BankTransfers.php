<?php
declare(strict_types=1);
namespace HomeAlone;

use Psr\Http\Message\UploadedFileInterface;

final class BankTransfers {
    public function __construct(private Database $db) {}

    public static function enabled(): bool {
        return !defined('RENDORA_PUBLIC_DEMO') && env('BANK_TRANSFERS_ENABLED')==='true'
            && (env('APP_ENV')!=='production'||env('ALLOW_LIVE_PAYMENTS')==='true');
    }

    private function requireEnabled(): void {
        if (!self::enabled()) throw new HttpError(404,'Transferências bancárias indisponíveis.');
    }

    private static function iban(string $value): string {
        $iban=strtoupper(preg_replace('/\s+/','',$value));
        if (!preg_match('/^PT50[0-9]{21}$/D',$iban)) throw new HttpError(422,'IBAN português inválido.');
        $digits=substr($iban,4).'252950';
        $remainder=0;
        foreach (str_split($digits) as $digit) $remainder=($remainder*10+(int)$digit)%97;
        if ($remainder!==1) throw new HttpError(422,'IBAN português inválido.');
        return $iban;
    }

    public function rentAccount(Scope $scope,string $contractId): array {
        $this->requireEnabled();
        $scope->requireRoles('ADMIN');
        $contract=$scope->contract($contractId);
        $account=$this->db->one('SELECT recipient_id,beneficiary,iban FROM rent_bank_accounts WHERE company_id=? AND contract_id=?',[$scope->companyId,$contractId]);
        return ['account'=>$account,'property_id'=>$contract['property_id']];
    }

    public function setRentAccount(Scope $scope,string $contractId,array $data): array {
        $this->requireEnabled();
        $scope->requireRoles('ADMIN');
        $recipient=Validate::text($data,'recipient_id',1,64);
        $beneficiary=Validate::text($data,'beneficiary',2,150);
        $iban=self::iban(Validate::text($data,'iban',25,42));
        return $this->db->transaction(function()use($scope,$contractId,$recipient,$beneficiary,$iban){
            $contract=$scope->contract($contractId);
            $member=$scope->member($recipient,['ADMIN','MANAGER','OWNER']);
            if ($member['role']==='OWNER') {
                (new Scope($this->db,$scope->companyId,['id'=>$recipient],'OWNER'))->property($contract['property_id']);
            }
            if ($this->db->one('SELECT contract_id FROM rent_bank_accounts WHERE contract_id=?',[$contractId])) {
                $this->db->run('UPDATE rent_bank_accounts SET recipient_id=?,beneficiary=?,iban=? WHERE contract_id=? AND company_id=?',[$recipient,$beneficiary,$iban,$contractId,$scope->companyId]);
            } else {
                $this->db->run('INSERT INTO rent_bank_accounts (contract_id,company_id,recipient_id,beneficiary,iban) VALUES (?,?,?,?,?)',[$contractId,$scope->companyId,$recipient,$beneficiary,$iban]);
            }
            $scope->audit('rent.bank_account.updated',$contractId);
            return ['recipient_id'=>$recipient,'beneficiary'=>$beneficiary,'iban'=>$iban];
        });
    }

    public function rent(Scope $scope,string $chargeId): array {
        $this->requireEnabled();
        $scope->requireRoles('ADMIN','MANAGER','FINANCE','OWNER','RESIDENT');
        $charge=$scope->charge($chargeId);
        $account=$this->db->one('SELECT recipient_id,beneficiary,iban FROM rent_bank_accounts WHERE company_id=? AND contract_id=?',[$scope->companyId,$charge['contract_id']]);
        if ($account&&!$this->db->one("SELECT id FROM memberships WHERE company_id=? AND user_id=? AND active=1 AND role IN ('OWNER','ADMIN','MANAGER')",[$scope->companyId,$account['recipient_id']])) $account=null;
        if ($account&&$scope->member($account['recipient_id'])['role']==='OWNER') {
            $owner=new Scope($this->db,$scope->companyId,['id'=>$account['recipient_id']],'OWNER');
            if (!$owner->allowed(fn()=>$owner->contract($charge['contract_id']))) $account=null;
        }
        $balance=(new Finance($this->db))->balance($chargeId);
        $proofs=array_filter($this->db->all('SELECT * FROM bank_transfer_proofs WHERE company_id=? AND charge_id=? ORDER BY created_at DESC',[$scope->companyId,$chargeId]),
            fn($proof)=>$scope->role==='ADMIN'||$proof['author_id']===$scope->user['id']||$proof['recipient_id']===$scope->user['id']);
        $instructions=$account&&$balance['confirmed']&&$balance['balance_cents']>0
            ?['beneficiary'=>$account['beneficiary'],'iban'=>$account['iban'],'amount_cents'=>$balance['balance_cents'],'reference'=>'R-'.$chargeId,'recipient_id'=>$account['recipient_id']]:null;
        if ($instructions) {
            $instructions['version']=self::version($instructions);
            unset($instructions['recipient_id']);
        }
        return ['instructions'=>$instructions,'proofs'=>array_values(array_map(function($proof)use($scope){
            $view=self::publicProof($proof,$scope->user['id']);
            $view['downloadable']=$proof['author_id']===$scope->user['id']||$proof['recipient_id']===$scope->user['id'];
            $view['rejectable']=$proof['status']==='PENDING'&&$scope->role==='ADMIN';
            if (!$view['downloadable']) unset($view['iban'],$view['beneficiary']);
            return $view;
        },$proofs))];
    }

    public function subscription(Scope $scope): array {
        $this->requireEnabled();
        $scope->requireRoles('ADMIN');
        return [
            'offers'=>array_map(fn($offer)=>[...$offer,'reference'=>'S-'.$scope->companyId,'version'=>self::version($offer)],$this->offers()),
            'proofs'=>array_map(fn($proof)=>self::publicProof($proof,$scope->user['id']),
                $this->db->all('SELECT * FROM bank_transfer_proofs WHERE company_id=? AND charge_id IS NULL ORDER BY created_at DESC',[$scope->companyId])),
        ];
    }

    private function offers(): array {
        if (!env('SUBSCRIPTION_BANK_IBAN')||!env('SUBSCRIPTION_BANK_BENEFICIARY')) return [];
        $iban=self::iban(env('SUBSCRIPTION_BANK_IBAN'));
        $offers=[];
        foreach (['ESSENTIAL','MANAGEMENT'] as $code) {
            $planId=env('BANK_PLAN_'.$code.'_ID');
            $plan=$this->db->one('SELECT id,name FROM plans WHERE id=? AND active=1',[$planId]);
            if (!$plan) continue;
            foreach ([1=>'MONTH',12=>'YEAR'] as $months=>$period) {
                $amount=env('BANK_PLAN_'.$code.'_'.$period.'_CENTS');
                if (!preg_match('/^[1-9][0-9]{0,7}$/D',$amount)) continue;
                $offers[]=['plan_id'=>$planId,'name'=>$plan['name'],'months'=>$months,'amount_cents'=>(int)$amount,
                    'beneficiary'=>env('SUBSCRIPTION_BANK_BENEFICIARY'),'iban'=>$iban];
            }
        }
        return $offers;
    }

    public function upload(Scope $scope,?string $chargeId,array $data,UploadedFileInterface $file): array {
        $this->requireEnabled();
        $scope->requireRoles($chargeId===null?'ADMIN':'RESIDENT');
        if ($file->getError()!==UPLOAD_ERR_OK||!$file->getSize()||$file->getSize()>5*1024*1024) throw new HttpError(422,'Comprovativo inválido. Limite: 5 MB.');
        $stream=$file->getStream();
        $stream->rewind();
        $bytes=$stream->getContents();
        if (strlen($bytes)>5*1024*1024||$bytes==='') throw new HttpError(422,'Comprovativo inválido. Limite: 5 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime,['application/pdf','image/jpeg','image/png'],true)) throw new HttpError(422,'Use PDF, JPG ou PNG.');
        $hash=hash('sha256',$bytes);
        $id=Database::id();
        $path=$this->filePath($id);
        try {
            return $this->db->transaction(function()use($scope,$chargeId,$data,$bytes,$mime,$hash,$id,$path){
                $planId=null;$months=null;$recipient=null;
                if ($chargeId!==null) {
                    if (PayPalSandbox::rentInProgress($this->db,$chargeId)) throw new HttpError(409,'Já existe um pagamento PayPal Sandbox em curso.');
                    $rent=$this->rent($scope,$chargeId);
                    $instructions=$rent['instructions'];
                    $charge=$scope->charge($chargeId);
                    $recipient=$this->db->one('SELECT recipient_id FROM rent_bank_accounts WHERE contract_id=? AND company_id=?',[$charge['contract_id'],$scope->companyId])['recipient_id']??null;
                } else {
                    $planId=Validate::text($data,'plan_id',1,64);
                    $months=(int)Validate::choice($data,'months',['1','12']);
                    $instructions=null;
                    foreach ($this->offers() as $offer) {
                        if ($offer['plan_id']===$planId&&$offer['months']===$months) $instructions=$offer;
                    }
                }
                $condition=$chargeId===null?'charge_id IS NULL':'charge_id=?';
                $args=$chargeId===null?[$scope->companyId]:[$scope->companyId,$chargeId];
                $existing=$this->db->one("SELECT * FROM bank_transfer_proofs WHERE company_id=? AND $condition AND file_hash=? AND author_id=? AND status IN ('PENDING','APPROVED')", [...$args,$hash,$scope->user['id']]);
                if ($existing&&$existing['plan_id']===$planId&&(int)$existing['months']===(int)$months) return self::publicProof($existing,$scope->user['id']);
                if (!$instructions) throw new HttpError(409,'Transferência indisponível para este pagamento.');
                $amount=(int)$instructions['amount_cents'];
                $expected=$chargeId===null?self::version($instructions):$instructions['version'];
                if (!hash_equals($expected,Validate::text($data,'version',64,64))) {
                    throw new HttpError(409,'Os dados da transferência mudaram. Atualize a página.');
                }
                if ($this->db->one("SELECT id FROM bank_transfer_proofs WHERE company_id=? AND $condition AND status='PENDING'", $args)) {
                    throw new HttpError(409,'Já existe um comprovativo por confirmar.');
                }
                if (!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true)&&!is_dir(dirname($path))) throw new \RuntimeException('Private proof storage unavailable.');
                if (file_put_contents($path,$bytes)!==strlen($bytes)||!chmod($path,0600)) throw new \RuntimeException('Proof could not be saved.');
                $proof=$this->db->insert('bank_transfer_proofs',[
                    'id'=>$id,'company_id'=>$scope->companyId,'charge_id'=>$chargeId,'plan_id'=>$planId,'months'=>$months,
                    'amount_cents'=>$amount,'beneficiary'=>$instructions['beneficiary'],'iban'=>$instructions['iban'],
                    'recipient_id'=>$recipient,'author_id'=>$scope->user['id'],'mime_type'=>$mime,'file_hash'=>$hash,'status'=>'PENDING',
                ]);
                $scope->audit('bank_transfer.submitted',$id);
                return self::publicProof($proof,$scope->user['id']);
            });
        } catch (\Throwable $error) {
            if (is_file($path)) unlink($path);
            throw $error;
        }
    }

    public function platformProofs(array $user): array {
        $this->requireEnabled();
        if (!$user['platform_admin']) throw new HttpError(403,'Acesso reservado.');
        return array_map(fn($row)=>[...$row,'extension'=>self::extension($row['mime_type'])],$this->db->all('SELECT p.id,p.status,p.amount_cents,p.months,p.paid_until,p.beneficiary,p.iban,p.mime_type,c.name company,pl.name plan FROM bank_transfer_proofs p JOIN companies c ON c.id=p.company_id JOIN plans pl ON pl.id=p.plan_id WHERE p.charge_id IS NULL ORDER BY p.created_at DESC LIMIT 100'));
    }

    public function review(?Scope $scope,array $user,string $id,array $data): array {
        $this->requireEnabled();
        $status=Validate::choice($data,'status',['APPROVED','REJECTED']);
        $reference=$status==='APPROVED'?mb_strtoupper(Validate::text($data,'bank_reference',3,150)):null;
        return $this->db->transaction(function()use($scope,$user,$id,$status,$reference){
            $proof=$this->proof($scope,$user,$id,$status);
            $referenceKey=$reference!==null?hash('sha256',$proof['iban'].':'.$reference):null;
            if ($proof['status']===$status&&$proof['bank_reference_key']===$referenceKey) return self::publicProof($proof,$user['id']);
            if ($proof['status']!=='PENDING') throw new HttpError(409,'Este comprovativo já foi revisto.');
            $until=null;
            if ($status==='APPROVED') {
                if ($this->db->one('SELECT id FROM bank_transfer_proofs WHERE bank_reference_key=?',[$referenceKey])) {
                    throw new HttpError(409,'Esta operação bancária já foi confirmada.');
                }
                if ($proof['charge_id']!==null) {
                    $balance=(new Finance($this->db))->balance($proof['charge_id']);
                    if (!$balance['confirmed']||(int)$proof['amount_cents']>$balance['balance_cents']
                        ||$this->db->one("SELECT id FROM checkouts WHERE charge_id=? AND status IN ('CREATING','OPEN','PROCESSING')",[$proof['charge_id']])) {
                        throw new HttpError(409,'O saldo mudou ou existe um pagamento online em curso.');
                    }
                    $this->db->insert('payments',['company_id'=>$proof['company_id'],'charge_id'=>$proof['charge_id'],
                        'amount_cents'=>$proof['amount_cents'],'kind'=>'PAYMENT','method'=>'MANUAL',
                        'reason'=>'Transferência bancária confirmada','external_id'=>'bank:'.$id,'idempotency_key'=>'bank:'.$id]);
                } else {
                    $company=$this->db->one('SELECT plan_id,stripe_customer_id,subscription_status FROM companies WHERE id=?',[$proof['company_id']]);
                    if ($company['stripe_customer_id']&&in_array($company['subscription_status'],['active','trialing','past_due'],true)) {
                        throw new HttpError(409,'Existe uma subscrição Stripe em curso.');
                    }
                    $last=$this->db->one("SELECT MAX(paid_until) paid_until FROM bank_transfer_proofs WHERE company_id=? AND charge_id IS NULL AND status='APPROVED'",[$proof['company_id']])['paid_until'];
                    if ($last>Database::today()&&$company['plan_id']!==$proof['plan_id']) {
                        throw new HttpError(409,'Aguarde o fim do período pago para alterar o plano.');
                    }
                    $start=new \DateTimeImmutable(max(Database::today(),$last??''));
                    $month=$start->modify('first day of this month')->modify('+'.(int)$proof['months'].' months');
                    $until=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$start->format('d'),(int)$month->format('t')))->format('Y-m-d');
                    $this->db->run("UPDATE companies SET plan_id=?,subscription_status='bank_transfer' WHERE id=?",[$proof['plan_id'],$proof['company_id']]);
                }
            }
            $this->db->run('UPDATE bank_transfer_proofs SET status=?,reviewer_id=?,paid_until=?,bank_reference_key=? WHERE id=?',[$status,$user['id'],$until,$referenceKey,$id]);
            (new Scope($this->db,$proof['company_id'],$user,$scope?->role??'PLATFORM'))->audit('bank_transfer.'.strtolower($status),$id);
            return self::publicProof([...$proof,'status'=>$status,'paid_until'=>$until],$user['id']);
        });
    }

    private function proof(?Scope $scope,array $user,string $id,?string $decision=null): array {
        $proof=$scope?$scope->row('bank_transfer_proofs',$id):$this->db->one('SELECT * FROM bank_transfer_proofs WHERE id=? AND charge_id IS NULL',[$id]);
        if (!$proof) throw new HttpError(404,'Comprovativo não encontrado.');
        if (!$scope) {
            if (!$user['platform_admin']) throw new HttpError(403,'Acesso reservado.');
        } elseif ($proof['charge_id']===null) {
            if ($decision!==null) throw new HttpError(403,'Acesso reservado.');
            $scope->requireRoles('ADMIN');
        } else {
            $scope->charge($proof['charge_id']);
            $recipient=$proof['recipient_id']===$user['id']&&in_array($scope->role,['OWNER','MANAGER','ADMIN'],true);
            $adminReject=$scope->role==='ADMIN'&&$decision==='REJECTED';
            if (!$recipient&&!$adminReject&&($decision!==null||$proof['author_id']!==$user['id'])) throw new HttpError(403,'Acesso reservado.');
        }
        return $proof;
    }

    public function download(?Scope $scope,array $user,string $id): array {
        $this->requireEnabled();
        $proof=$this->proof($scope,$user,$id);
        $path=$this->filePath($id);
        if (!is_file($path)) throw new HttpError(404,'Comprovativo não encontrado.');
        $extension=self::extension($proof['mime_type']);
        return [$path,$proof['mime_type'],'comprovativo.'.$extension];
    }

    private static function version(array $instructions): string {
        return hash('sha256',json_encode($instructions,JSON_THROW_ON_ERROR));
    }

    private function filePath(string $id): string {
        return dirname(__DIR__).'/storage/bank-proofs/'.$id;
    }

    private static function extension(string $mime): string {
        return ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$mime];
    }

    private static function publicProof(array $proof,string $userId): array {
        return [
            'id'=>$proof['id'],'status'=>$proof['status'],'extension'=>self::extension($proof['mime_type']),'amount_cents'=>(int)$proof['amount_cents'],
            'plan_id'=>$proof['plan_id'],'months'=>$proof['months']===null?null:(int)$proof['months'],
            'paid_until'=>$proof['paid_until']??null,'beneficiary'=>$proof['beneficiary'],'iban'=>$proof['iban'],'reviewable'=>$proof['status']==='PENDING'&&$proof['recipient_id']===$userId,
        ];
    }
}
