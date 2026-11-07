<?php
declare(strict_types=1);
namespace HomeAlone;
final class Payments {
    public function __construct(private Database $db) {}
    public function client(): \Stripe\StripeClient {
        $key=env('STRIPE_SECRET_KEY');if(!$key)throw new HttpError(503,'Configure a Stripe para ativar pagamentos online.');
        if(str_starts_with($key,'sk_live_')&&(env('ALLOW_LIVE_PAYMENTS')!=='true'||env('DEMO_MODE')==='true'))throw new HttpError(503,'Pagamentos reais ainda não estão ativados.');return new \Stripe\StripeClient($key);
    }
    public function connect(Scope $s): array {
        $s->requireRoles('ADMIN');$stripe=$this->client();$company=$this->db->one('SELECT * FROM companies WHERE id=?',[$s->companyId]);
        if(!$company['stripe_account_id']){$account=$stripe->accounts->create(['type'=>'standard','country'=>'PT','email'=>$company['email']],['idempotency_key'=>'connect-'.$s->companyId]);$this->db->run('UPDATE companies SET stripe_account_id=? WHERE id=?',[$account->id,$s->companyId]);$company['stripe_account_id']=$account->id;}
        $link=$stripe->accountLinks->create(['account'=>$company['stripe_account_id'],'refresh_url'=>env('APP_URL').'/?page=settings','return_url'=>env('APP_URL').'/?page=settings','type'=>'account_onboarding']);return ['url'=>$link->url];
    }
    public function checkout(Scope $s,string $id): array {
        $s->requireRoles('ADMIN','MANAGER','FINANCE','RESIDENT');$s->charge($id);$stripe=$this->client();$company=$this->db->one('SELECT * FROM companies WHERE id=?',[$s->companyId]);if(!$company['stripe_account_id'])throw new HttpError(503,'A empresa ainda não ligou a conta Stripe.');
        $account=$stripe->accounts->retrieve($company['stripe_account_id']);if(!$account->charges_enabled)throw new HttpError(503,'A conta da empresa ainda não pode receber pagamentos.');
        $co=$this->db->transaction(function()use($s,$id){if(PayPalSandbox::rentInProgress($this->db,$id))throw new HttpError(409,'Já existe um pagamento PayPal Sandbox em curso.');$b=(new Finance($this->db))->balance($id);if(!$b['confirmed']||$b['balance_cents']<=0)throw new HttpError(409,'Esta cobrança não está disponível para pagamento.');$existing=$this->db->one("SELECT * FROM checkouts WHERE charge_id=? AND status IN ('CREATING','OPEN','PROCESSING')",[$id]);if($existing)return $existing;return $this->db->insert('checkouts',['company_id'=>$s->companyId,'charge_id'=>$id,'amount_cents'=>$b['balance_cents'],'expires_at'=>gmdate('Y-m-d H:i:s',time()+86400)]);});
        if(!empty($co['stripe_session_id'])){ $session=$stripe->checkout->sessions->retrieve($co['stripe_session_id'],[],['stripe_account'=>$company['stripe_account_id']]);if($session->status==='expired'){$this->db->run("UPDATE checkouts SET status='EXPIRED' WHERE id=? AND status='OPEN'",[$co['id']]);return $this->checkout($s,$id);}if($session->status==='complete')throw new HttpError(409,'Pagamento em processamento. Aguarde a confirmação.');return ['url'=>$session->url]; }
        $session=$stripe->checkout->sessions->create(['mode'=>'payment','client_reference_id'=>$co['id'],'metadata'=>['checkout_id'=>$co['id']],'payment_intent_data'=>['metadata'=>['checkout_id'=>$co['id']]],'line_items'=>[['price_data'=>['currency'=>'eur','unit_amount'=>$co['amount_cents'],'product_data'=>['name'=>'Renda · '.(new Finance($this->db))->balance($id)['period']]],'quantity'=>1]],'success_url'=>env('APP_URL').'/?page=payments&payment=processing','cancel_url'=>env('APP_URL').'/?page=payments','expires_at'=>strtotime($co['expires_at'].' UTC')],['stripe_account'=>$company['stripe_account_id'],'idempotency_key'=>'checkout-'.$co['id']]);
        $this->db->run("UPDATE checkouts SET stripe_session_id=?,url=?,status=CASE WHEN status='CREATING' THEN 'OPEN' ELSE status END WHERE id=?",[$session->id,$session->url,$co['id']]);return ['url'=>$session->url];
    }
    public function webhook(string $payload,string $signature,bool $billing=false): array {
        $secret=env($billing?'STRIPE_BILLING_WEBHOOK_SECRET':'STRIPE_WEBHOOK_SECRET');if(!$secret)throw new HttpError(503,'Webhook não configurado.');
        try{$event=\Stripe\Webhook::constructEvent($payload,$signature,$secret);}catch(\Throwable){throw new HttpError(400,'Assinatura do webhook inválida.');}
        if($event->livemode&&env('ALLOW_LIVE_PAYMENTS')!=='true')throw new HttpError(400,'Eventos reais desativados.');
        if($billing)return $this->billingEvent($event->toArray());return $this->applyEvent($event->toArray());
    }
    /** Only call after signature verification. Public for deterministic event-order tests. */
    public function applyEvent(array $e): array {
        return $this->db->transaction(function()use($e){
            if($this->db->one('SELECT id FROM webhook_events WHERE id=?',[$e['id']]))return ['received'=>true,'duplicate'=>true];
            $company=$this->db->one('SELECT * FROM companies WHERE stripe_account_id=?',[$e['account']??'']);if(!$company)throw new HttpError(400,'Conta de pagamento desconhecida.');
            $o=$e['data']['object'];$type=$e['type'];$checkoutId=$o['metadata']['checkout_id']??$o['client_reference_id']??null;
            $co=$checkoutId?$this->db->one('SELECT * FROM checkouts WHERE company_id=? AND id=?',[$company['id'],$checkoutId]):null;
            if(!$co&&!empty($o['payment_intent']))$co=$this->db->one('SELECT * FROM checkouts WHERE company_id=? AND payment_intent=?',[$company['id'],$o['payment_intent']]);
            if(!$co&&str_starts_with($type,'checkout.session.'))$co=$this->db->one('SELECT * FROM checkouts WHERE company_id=? AND stripe_session_id=?',[$company['id'],$o['id']]);
            $financial=in_array($type,['payment_intent.succeeded','checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','checkout.session.expired','refund.created','refund.updated','charge.dispute.created','charge.dispute.closed']);
            if($financial&&!$co)throw new HttpError(409,'Pagamento ainda não associado; repetir evento.');
            if($co){
                if($type==='payment_intent.succeeded'||in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded'])){
                    $intent=$type==='payment_intent.succeeded'?$o['id']:($o['payment_intent']??null);if($intent)$this->db->run('UPDATE checkouts SET payment_intent=? WHERE id=?',[$intent,$co['id']]);
                    $paid=$type==='payment_intent.succeeded'||($o['payment_status']??'')==='paid';
                    if($paid){$amount=$o['amount_received']??$o['amount_total']??0;if($amount!==$co['amount_cents']||($o['currency']??'')!=='eur'||!$intent)throw new HttpError(400,'Montante ou moeda incompatível.');
                        $key='stripe-payment:'.$intent;if(!$this->db->one('SELECT id FROM payments WHERE idempotency_key=?',[$key]))$this->db->insert('payments',['company_id'=>$company['id'],'charge_id'=>$co['charge_id'],'amount_cents'=>$amount,'kind'=>'PAYMENT','method'=>'STRIPE','external_id'=>$intent,'idempotency_key'=>$key]);$this->db->run("UPDATE checkouts SET status='SUCCEEDED' WHERE id=?",[$co['id']]);
                    }else $this->db->run("UPDATE checkouts SET status='PROCESSING' WHERE id=? AND status IN ('OPEN','CREATING')",[$co['id']]);
                }elseif(in_array($type,['checkout.session.async_payment_failed','checkout.session.expired']))$this->db->run("UPDATE checkouts SET status=? WHERE id=? AND status<>'SUCCEEDED'",[$type==='checkout.session.expired'?'EXPIRED':'FAILED',$co['id']]);
                elseif(in_array($type,['refund.created','refund.updated'])&&($o['status']??'')==='succeeded'){$this->ensureOriginalPayment($co);$key='stripe-refund:'.$o['id'];if(!$this->db->one('SELECT id FROM payments WHERE idempotency_key=?',[$key]))$this->db->insert('payments',['company_id'=>$company['id'],'charge_id'=>$co['charge_id'],'amount_cents'=>$o['amount'],'kind'=>'REFUND','method'=>'STRIPE','reason'=>'Reembolso confirmado pela Stripe','external_id'=>$o['id'],'idempotency_key'=>$key]);}
                elseif(in_array($type,['charge.dispute.created','charge.dispute.closed'])){$this->ensureOriginalPayment($co);$key='dispute:'.$o['id'];if(!$this->db->one('SELECT id FROM payments WHERE idempotency_key=?',[$key]))$this->db->insert('payments',['company_id'=>$company['id'],'charge_id'=>$co['charge_id'],'amount_cents'=>$o['amount'],'kind'=>'DISPUTE','method'=>'STRIPE','reason'=>'Pagamento contestado','idempotency_key'=>$key]);if($type==='charge.dispute.closed'&&$o['status']==='won'&&!$this->db->one('SELECT id FROM payments WHERE idempotency_key=?',[$key.':won']))$this->db->insert('payments',['company_id'=>$company['id'],'charge_id'=>$co['charge_id'],'amount_cents'=>$o['amount'],'kind'=>'DISPUTE_WON','method'=>'STRIPE','reason'=>'Contestação resolvida a favor da empresa','idempotency_key'=>$key.':won']);}
            }
            $this->db->insert('webhook_events',['id'=>$e['id'],'company_id'=>$company['id'],'type'=>$type]);return ['received'=>true];
        });
    }
    private function ensureOriginalPayment(array $co): void {if(!$this->db->one("SELECT id FROM payments WHERE charge_id=? AND external_id=? AND kind='PAYMENT'",[$co['charge_id'],$co['payment_intent']]))throw new HttpError(409,'Confirmação original pendente; repetir evento.');}
    public function refund(Scope $s,string $paymentId,array $d): array {
        $s->requireRoles('ADMIN','FINANCE');$p=$s->row('payments',$paymentId);if($p['kind']!=='PAYMENT')throw new HttpError(422,'Só pagamentos podem ser reembolsados.');$amount=Validate::integer($d,'amount_cents',1);$key=Validate::text($d,'idempotency_key',8,100);$reason=Validate::text($d,'reason',3,1000);
        if($amount>$p['amount_cents'])throw new HttpError(422,'O reembolso excede o pagamento.');
        if($p['method']==='STRIPE'){$company=$this->db->one('SELECT * FROM companies WHERE id=?',[$s->companyId]);$refund=$this->client()->refunds->create(['payment_intent'=>$p['external_id'],'amount'=>$amount,'metadata'=>['reason'=>$reason]],['stripe_account'=>$company['stripe_account_id'],'idempotency_key'=>'refund:'.$s->companyId.':'.$key]);$s->audit('refund.requested',$p['id']);return ['status'=>$refund->status,'id'=>$refund->id];}
        return $this->db->transaction(function()use($s,$p,$amount,$reason,$key){$key='refund:'.$s->companyId.':'.$key;$existing=$this->db->one('SELECT * FROM payments WHERE idempotency_key=?',[$key]);if($existing){if($existing['charge_id']!==$p['charge_id']||$existing['amount_cents']!==$amount)throw new HttpError(409,'Chave já utilizada.');return $existing;}$refunded=(int)$this->db->one("SELECT COALESCE(SUM(amount_cents),0) n FROM payments WHERE kind='REFUND' AND external_id LIKE ?",['manual:'.$p['id'].':%'])['n'];if($refunded+$amount>$p['amount_cents'])throw new HttpError(422,'Montante já reembolsado.');$r=$this->db->insert('payments',['company_id'=>$s->companyId,'charge_id'=>$p['charge_id'],'amount_cents'=>$amount,'kind'=>'REFUND','method'=>'MANUAL','reason'=>$reason,'external_id'=>'manual:'.$p['id'].':'.Database::id(),'idempotency_key'=>$key]);$s->audit('refund.manual',$r['id']);return $r;});
    }
    public function billing(Scope $s,array $d): array {
        $s->requireRoles('ADMIN');$plan=$this->db->one('SELECT * FROM plans WHERE id=? AND active=1',[Validate::text($d,'plan_id')]);if(!$plan||!$plan['stripe_price_id'])throw new HttpError(503,'Plano comercial ainda não configurado.');$stripe=$this->client();$company=$this->db->one('SELECT * FROM companies WHERE id=?',[$s->companyId]);
        if(!$company['stripe_customer_id']){$customer=$stripe->customers->create(['email'=>$company['email'],'name'=>$company['name'],'metadata'=>['company_id'=>$s->companyId]],['idempotency_key'=>'customer:'.$s->companyId]);$company['stripe_customer_id']=$customer->id;$this->db->run('UPDATE companies SET stripe_customer_id=? WHERE id=?',[$customer->id,$s->companyId]);}
        if(in_array($company['subscription_status'],['active','trialing','past_due']))return ['url'=>$stripe->billingPortal->sessions->create(['customer'=>$company['stripe_customer_id'],'return_url'=>env('APP_URL').'/?page=settings'])->url];
        $session=$stripe->checkout->sessions->create(['mode'=>'subscription','customer'=>$company['stripe_customer_id'],'line_items'=>[['price'=>$plan['stripe_price_id'],'quantity'=>1]],'subscription_data'=>['metadata'=>['company_id'=>$s->companyId,'plan_id'=>$plan['id']]],'success_url'=>env('APP_URL').'/?page=settings','cancel_url'=>env('APP_URL').'/?page=settings'],['idempotency_key'=>'subscription:'.$s->companyId.':'.$plan['id'].':'.gmdate('Y-m-d-H')]);return ['url'=>$session->url];
    }
    private function billingEvent(array $e): array {
        if(!str_starts_with($e['type'],'customer.subscription.'))return ['received'=>true];
        // Read current provider state so an older webhook cannot overwrite a newer status.
        $subscription=$this->client()->subscriptions->retrieve($e['data']['object']['id']);$company=$this->db->one('SELECT id FROM companies WHERE stripe_customer_id=?',[$subscription->customer]);if(!$company)throw new HttpError(400,'Subscrição desconhecida.');
        return $this->db->transaction(function()use($e,$subscription,$company){if($this->db->one('SELECT id FROM webhook_events WHERE id=?',[$e['id']]))return ['received'=>true];$plan=$this->db->one('SELECT id FROM plans WHERE stripe_price_id=?',[$subscription->items->data[0]->price->id??'']);$this->db->run('UPDATE companies SET subscription_status=?,plan_id=? WHERE id=?',[$subscription->status,$plan['id']??null,$company['id']]);$this->db->insert('webhook_events',['id'=>$e['id'],'company_id'=>$company['id'],'type'=>$e['type']]);return ['received'=>true];});
    }
}
