<?php
declare(strict_types=1);
namespace HomeAlone;

final class PayPalSandbox {
    public function __construct(private Database $db,private ?PayPalSandboxClient $client=null) {}

    public static function enabled(): bool {
        return !defined('RENDORA_PUBLIC_DEMO')&&env('APP_ENV')==='test'
            &&env('PAYPAL_SANDBOX_ENABLED')==='true'&&env('ALLOW_LIVE_PAYMENTS')!=='true';
    }

    public static function rentInProgress(Database $db,string $chargeId): bool {
        return self::enabled()&&(bool)$db->one("SELECT id FROM paypal_intents WHERE charge_id=? AND status IN ('CREATING','PENDING')",[$chargeId]);
    }

    private function api(): PayPalSandboxClient {
        if (!self::enabled()) throw new HttpError(404,'PayPal Sandbox indisponível.');
        return $this->client??=PayPalSandboxClient::configured();
    }

    private function beneficiary(Scope $scope,array $charge): ?array {
        $contract=$scope->contract($charge['contract_id']);
        $bank=$this->db->one('SELECT recipient_id,beneficiary FROM rent_bank_accounts WHERE company_id=? AND contract_id=?',[$scope->companyId,$contract['id']]);
        if (!$bank) return null;
        $member=$this->db->one('SELECT role FROM memberships WHERE company_id=? AND user_id=? AND active=1',[$scope->companyId,$bank['recipient_id']]);
        if (!in_array($member['role']??null,['ADMIN','MANAGER','OWNER'],true)) return null;
        if ($member['role']==='OWNER') {
            $owner=new Scope($this->db,$scope->companyId,['id'=>$bank['recipient_id']],'OWNER');
            if (!$owner->allowed(fn()=>$owner->property($contract['property_id']))) return null;
        }
        $apps=$this->api()->apps();
        $platformMerchant=$apps['subscriptions']['merchant_id']??null;
        if (!$platformMerchant) return null;
        foreach ($apps as $key=>$app) {
            if ($key!=='subscriptions'&&($app['company_id']??null)===$scope->companyId
                &&($app['recipient_id']??null)===$bank['recipient_id']&&!empty($app['merchant_id'])&&$app['merchant_id']!==$platformMerchant) {
                $this->api()->app($key);
                return [...$bank,'app_key'=>$key,'merchant_id'=>$app['merchant_id']];
            }
        }
        return null;
    }

    public function rent(Scope $scope,string $chargeId): array {
        $this->api();
        $scope->requireRoles('RESIDENT');
        $charge=$scope->charge($chargeId);
        $recipient=$this->beneficiary($scope,$charge);
        return ['beneficiary'=>$recipient['beneficiary']??null,'payment'=>self::statusView($this->latest($scope->companyId,$chargeId))];
    }

    public function startRent(Scope $scope,string $chargeId): array {
        $api=$this->api();
        $scope->requireRoles('RESIDENT');
        $intent=$this->db->transaction(function()use($scope,$chargeId){
            $charge=$scope->charge($chargeId);
            $recipient=$this->beneficiary($scope,$charge);
            $balance=(new Finance($this->db))->balance($chargeId);
            if (!$recipient||!$charge['confirmed']||$balance['balance_cents']<=0) throw new HttpError(409,'Pagamento PayPal indisponível.');
            if ($this->db->one("SELECT id FROM checkouts WHERE charge_id=? AND status IN ('CREATING','OPEN','PROCESSING')",[$chargeId])
                ||$this->db->one("SELECT id FROM bank_transfer_proofs WHERE charge_id=? AND status='PENDING'",[$chargeId])) {
                throw new HttpError(409,'Já existe um pagamento em curso.');
            }
            $existing=$this->latest($scope->companyId,$chargeId);
            if ($existing&&in_array($existing['status'],['CREATING','PENDING'],true)) {
                if ($existing['recipient_id']!==$recipient['recipient_id']||$existing['merchant_id']!==$recipient['merchant_id']
                    ||$existing['app_key']!==$recipient['app_key']||(int)$existing['amount_cents']!==$balance['balance_cents']) {
                    throw new HttpError(409,'Os dados da cobrança mudaram. O pedido anterior exige revisão.');
                }
                return $existing;
            }
            return $this->db->insert('paypal_intents',[
                'company_id'=>$scope->companyId,'charge_id'=>$chargeId,'app_key'=>$recipient['app_key'],
                'recipient_id'=>$recipient['recipient_id'],'beneficiary'=>$recipient['beneficiary'],'merchant_id'=>$recipient['merchant_id'],
                'amount_cents'=>$balance['balance_cents'],'status'=>'CREATING',
            ]);
        });
        if (!empty($intent['approval_url'])) return ['url'=>$intent['approval_url']];
        $return=rtrim(env('APP_URL'),'/').'/?page=payments';
        $order=$api->call($intent['app_key'],'POST','/v2/checkout/orders',[
            'intent'=>'CAPTURE','purchase_units'=>[[
                'reference_id'=>$intent['id'],'payee'=>['merchant_id'=>$intent['merchant_id']],
                'description'=>'Renda fictícia Rendora Sandbox',
                'amount'=>['currency_code'=>'EUR','value'=>self::euros((int)$intent['amount_cents'])],
            ]],
            'payment_source'=>['paypal'=>['experience_context'=>[
                'shipping_preference'=>'NO_SHIPPING','user_action'=>'PAY_NOW','return_url'=>$return,'cancel_url'=>$return,
            ]]],
        ],$intent['id']);
        $remoteId=Validate::text($order,'id',1,64);
        $created=$api->call($intent['app_key'],'GET','/v2/checkout/orders/'.rawurlencode($remoteId));
        $this->verifyRentOrder([...$intent,'remote_id'=>$remoteId],$created);
        return $this->saveApproval($intent,$order);
    }

    public function subscription(Scope $scope): array {
        $this->api();
        $scope->requireRoles('ADMIN');
        return ['offers'=>array_map(fn($offer)=>array_intersect_key($offer,array_flip(['plan_id','name','months','amount_cents'])),$this->offers()),
            'payment'=>self::statusView($this->latest($scope->companyId,null))];
    }

    private function offers(): array {
        $app=$this->api()->apps()['subscriptions']??[];
        $offers=[];
        foreach (($app['plans']??[]) as $offer) {
            if (!isset($offer['plan_id'],$offer['paypal_plan_id'],$offer['months'],$offer['amount_cents'])
                ||!in_array($offer['months'],[1,12],true)||!is_int($offer['amount_cents'])||$offer['amount_cents']<1) continue;
            $plan=$this->db->one('SELECT name FROM plans WHERE id=? AND active=1',[$offer['plan_id']]);
            if ($plan) $offers[]=[...$offer,'name'=>$plan['name']];
        }
        return $offers;
    }

    public function startSubscription(Scope $scope,array $input): array {
        $api=$this->api();
        $scope->requireRoles('ADMIN');
        $plan=Validate::text($input,'plan_id',1,64);
        $months=Validate::integer($input,'months',1,12);
        $offer=null;
        foreach ($this->offers() as $candidate) if ($candidate['plan_id']===$plan&&$candidate['months']===$months) $offer=$candidate;
        if (!$offer) throw new HttpError(422,'Plano PayPal Sandbox indisponível.');
        $intent=$this->db->transaction(function()use($scope,$offer){
            $existing=$this->latest($scope->companyId,null);
            if ($existing&&!in_array($existing['status'],['CANCELLED','EXPIRED'],true)) {
                if (in_array($existing['status'],['CREATING','PENDING'],true)&&$existing['paypal_plan_id']===$offer['paypal_plan_id']) return $existing;
                throw new HttpError(409,'Já existe uma subscrição PayPal Sandbox.');
            }
            return $this->db->insert('paypal_intents',[
                'company_id'=>$scope->companyId,'plan_id'=>$offer['plan_id'],'app_key'=>'subscriptions',
                'paypal_plan_id'=>$offer['paypal_plan_id'],'amount_cents'=>$offer['amount_cents'],'status'=>'CREATING',
            ]);
        });
        if (!empty($intent['approval_url'])) return ['url'=>$intent['approval_url']];
        $remotePlan=$api->call('subscriptions','GET','/v1/billing/plans/'.rawurlencode($intent['paypal_plan_id']));
        $cycles=$remotePlan['billing_cycles']??[];
        $cycle=$cycles[0]??[];
        $frequency=$cycle['frequency']??[];
        $period=($frequency['interval_unit']??'')==='YEAR'?12:1;
        if (($remotePlan['status']??'')!=='ACTIVE'||count($cycles)!==1||($cycle['tenure_type']??'')!=='REGULAR'
            ||!in_array($frequency['interval_unit']??'',['MONTH','YEAR'],true)
            ||$period*(int)($frequency['interval_count']??0)!==$offer['months']
            ||!self::amountMatches($cycle['pricing_scheme']['fixed_price']??[],(int)$intent['amount_cents'])
            ||(float)($remotePlan['payment_preferences']['setup_fee']['value']??0)!==0.0) {
            throw new HttpError(409,'O plano no PayPal não coincide com a oferta da Rendora.');
        }
        $return=rtrim(env('APP_URL'),'/').'/?page=settings';
        $subscription=$api->call('subscriptions','POST','/v1/billing/subscriptions',[
            'plan_id'=>$intent['paypal_plan_id'],'custom_id'=>$intent['id'],
            'application_context'=>['user_action'=>'SUBSCRIBE_NOW','return_url'=>$return,'cancel_url'=>$return],
        ],$intent['id']);
        return $this->saveApproval($intent,$subscription);
    }

    private function saveApproval(array $intent,array $remote): array {
        $url='';
        foreach (($remote['links']??[]) as $link) if (in_array($link['rel']??'', ['approve','payer-action'],true)) $url=$link['href']??'';
        $parts=parse_url($url);
        if (($parts['scheme']??'')!=='https'||!in_array($parts['host']??'',['www.sandbox.paypal.com','sandbox.paypal.com'],true)
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||empty($remote['id'])) throw new HttpError(502,'Ligação de aprovação PayPal inválida.');
        $this->db->run("UPDATE paypal_intents SET remote_id=?,approval_url=?,status=CASE WHEN status='CREATING' THEN 'PENDING' ELSE status END WHERE id=?",
            [$remote['id'],$url,$intent['id']]);
        return ['url'=>$url];
    }

    private static function statusView(?array $intent): ?array {
        return $intent?['status'=>$intent['status'],'covered_until'=>$intent['covered_until']??null]:null;
    }

    private function latest(string $company,?string $charge): ?array {
        return $this->db->one('SELECT * FROM paypal_intents WHERE company_id=? AND '.($charge===null?'charge_id IS NULL':'charge_id=?').' ORDER BY created_at DESC LIMIT 1',
            $charge===null?[$company]:[$company,$charge]);
    }

    public function reconcileRent(Scope $scope,string $charge): array {
        $this->api();
        $scope->requireRoles('RESIDENT');
        $scope->charge($charge);
        $intent=$this->latest($scope->companyId,$charge);
        if (!$intent||!$intent['remote_id']) throw new HttpError(404,'Pedido PayPal não encontrado.');
        $this->recordRent($intent,true);
        return ['status'=>$this->latest($scope->companyId,$charge)['status']];
    }

    public function webhook(string $appKey,array $event,array $headers,?string $rawEvent=null): array {
        $api=$this->api();
        $app=$api->app($appKey);
        if (empty($app['webhook_id'])) throw new HttpError(503,'Webhook PayPal Sandbox por configurar.');
        $verify=['webhook_id'=>$app['webhook_id']];
        foreach (['auth_algo','cert_url','transmission_id','transmission_sig','transmission_time'] as $name) {
            if (empty($headers[$name])) throw new HttpError(400,'Assinatura PayPal em falta.');
            $verify[$name]=$headers[$name];
        }
        // PayPal signs the original event body. Embed it unchanged inside the verification request.
        $envelope=json_encode($verify,JSON_THROW_ON_ERROR);
        $rawEvent??=json_encode($event,JSON_THROW_ON_ERROR);
        $verificationBody=substr($envelope,0,-1).',"webhook_event":'.$rawEvent.'}';
        if (($api->call($appKey,'POST','/v1/notifications/verify-webhook-signature',$verificationBody)['verification_status']??null)!=='SUCCESS') {
            throw new HttpError(400,'Assinatura PayPal inválida.');
        }
        $type=Validate::text($event,'event_type',3,100);
        $eventId='pp:'.substr(hash('sha256',$appKey.':'.Validate::text($event,'id',1,120)),0,60);
        if ($this->db->one('SELECT id FROM webhook_events WHERE id=?',[$eventId])) return ['received'=>true];
        $resource=$event['resource']??[];
        $remote=match($type){
            'CHECKOUT.ORDER.APPROVED'=>$resource['id']??null,
            'PAYMENT.CAPTURE.COMPLETED'=>$resource['supplementary_data']['related_ids']['order_id']??null,
            'PAYMENT.SALE.COMPLETED'=>$resource['billing_agreement_id']??null,
            'BILLING.SUBSCRIPTION.ACTIVATED','BILLING.SUBSCRIPTION.CANCELLED','BILLING.SUBSCRIPTION.SUSPENDED','BILLING.SUBSCRIPTION.EXPIRED','BILLING.SUBSCRIPTION.PAYMENT.FAILED'=>$resource['id']??null,
            default=>null,
        };
        if (!is_string($remote)||$remote==='') return ['received'=>true];
        $intent=$this->db->one('SELECT * FROM paypal_intents WHERE app_key=? AND remote_id=?',[$appKey,$remote]);
        if (!$intent) return ['received'=>true];
        if ($intent['charge_id']!==null&&in_array($type,['CHECKOUT.ORDER.APPROVED','PAYMENT.CAPTURE.COMPLETED'],true)) {
            if (!$this->recordRent($intent,$type==='CHECKOUT.ORDER.APPROVED')) throw new HttpError(409,'Captura PayPal ainda não confirmada.');
        } elseif ($intent['charge_id']===null&&($type==='PAYMENT.SALE.COMPLETED'||str_starts_with($type,'BILLING.SUBSCRIPTION.'))) {
            $this->recordSubscription($intent,$type,$resource);
        } else return ['received'=>true];
        $this->db->transaction(function()use($eventId,$intent,$type){
            if (!$this->db->one('SELECT id FROM webhook_events WHERE id=?',[$eventId])) {
                $this->db->insert('webhook_events',['id'=>$eventId,'company_id'=>$intent['company_id'],'type'=>$type]);
            }
        });
        return ['received'=>true];
    }

    private function recordRent(array $intent,bool $capture): bool {
        if ($intent['status']==='COMPLETED') return true;
        $api=$this->api();
        $path='/v2/checkout/orders/'.rawurlencode($intent['remote_id']);
        $order=$api->call($intent['app_key'],'GET',$path);
        $this->verifyRentOrder($intent,$order);
        if (($order['status']??'')==='VOIDED') {
            $this->db->run("UPDATE paypal_intents SET status='CANCELLED' WHERE id=? AND status='PENDING'",[$intent['id']]);
            return false;
        }
        if ($capture&&($order['status']??'')==='APPROVED') {
            $scope=new Scope($this->db,$intent['company_id'],[],'ADMIN');
            $recipient=$this->beneficiary($scope,$scope->charge($intent['charge_id']));
            $balance=(new Finance($this->db))->balance($intent['charge_id']);
            if (($recipient['merchant_id']??null)!==$intent['merchant_id']||($recipient['recipient_id']??null)!==$intent['recipient_id']
                ||!$balance['confirmed']||(int)$intent['amount_cents']!==$balance['balance_cents']) {
                throw new HttpError(409,'O beneficiário ou o saldo mudou. A captura exige revisão.');
            }
            $api->call($intent['app_key'],'POST',$path.'/capture',[],'cap-'.$intent['id']);
            $order=$api->call($intent['app_key'],'GET',$path);
            $this->verifyRentOrder($intent,$order);
        }
        $captures=$order['purchase_units'][0]['payments']['captures']??[];
        if (($order['status']??'')!=='COMPLETED'||count($captures)!==1||($captures[0]['status']??'')!=='COMPLETED') return false;
        $payment=$captures[0];
        if (empty($payment['id'])||!self::amountMatches($payment['amount']??[],(int)$intent['amount_cents'])) {
            throw new HttpError(409,'O montante capturado pelo PayPal não coincide.');
        }
        $this->db->transaction(function()use($intent,$payment){
            $current=$this->db->one('SELECT status FROM paypal_intents WHERE id=?',[$intent['id']]);
            if ($current['status']==='COMPLETED') return;
            $balance=(new Finance($this->db))->balance($intent['charge_id']);
            if (!$balance['confirmed']||(int)$intent['amount_cents']>$balance['balance_cents']) throw new HttpError(409,'O saldo mudou. A captura exige reconciliação.');
            $key='paypal-sandbox:'.$payment['id'];
            $this->db->insert('payments',[
                'company_id'=>$intent['company_id'],'charge_id'=>$intent['charge_id'],'amount_cents'=>$intent['amount_cents'],
                'kind'=>'PAYMENT','method'=>'PAYPAL_SANDBOX','external_id'=>$payment['id'],'idempotency_key'=>$key,
                'reason'=>'Captura PayPal Sandbox',
            ]);
            $this->db->run("UPDATE paypal_intents SET status='COMPLETED' WHERE id=?",[$intent['id']]);
        });
        return true;
    }

    private function verifyRentOrder(array $intent,array $order): void {
        $unit=$order['purchase_units'][0]??[];
        if (($order['id']??'')!==$intent['remote_id']||count($order['purchase_units']??[])!==1
            ||($unit['reference_id']??'')!==$intent['id']||($unit['payee']['merchant_id']??'')!==$intent['merchant_id']
            ||!self::amountMatches($unit['amount']??[],(int)$intent['amount_cents'])) {
            throw new HttpError(409,'O beneficiário ou o montante no PayPal não coincide.');
        }
    }

    private function recordSubscription(array $intent,string $type,array $resource): void {
        $remote=$this->api()->call('subscriptions','GET','/v1/billing/subscriptions/'.rawurlencode($intent['remote_id']));
        if (($remote['id']??'')!==$intent['remote_id']||($remote['plan_id']??'')!==$intent['paypal_plan_id']||($remote['custom_id']??'')!==$intent['id']) {
            throw new HttpError(409,'A subscrição PayPal não coincide com o pedido.');
        }
        $status=(string)($remote['status']??'');
        if (!in_array($status,['APPROVAL_PENDING','APPROVED','ACTIVE','SUSPENDED','CANCELLED','EXPIRED'],true)) {
            throw new HttpError(409,'Estado PayPal inesperado.');
        }
        $saleId=null;
        $sale=$type==='PAYMENT.SALE.COMPLETED';
        $until=null;
        if ($sale) {
            $saleId=Validate::text($resource,'id',1,64);
            $amount=$resource['amount']??[];
            if (($resource['state']??'')!=='completed'||!self::amountMatches([
                'currency_code'=>$amount['currency']??'','value'=>$amount['total']??'',
            ],(int)$intent['amount_cents'])) throw new HttpError(409,'O pagamento da subscrição não coincide.');
            $next=$remote['billing_info']['next_billing_time']??null;
            if ($next) $until=(new \DateTimeImmutable($next))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            if ($status==='ACTIVE'&&!$until) throw new HttpError(409,'Período pago ainda por confirmar no PayPal.');
        }
        $this->db->transaction(function()use($intent,$status,$sale,$saleId,$until){
            if ($sale) {
                $existing=$this->db->one('SELECT intent_id FROM paypal_subscription_payments WHERE id=?',[$saleId]);
                if ($existing) {
                    if ($existing['intent_id']!==$intent['id']) throw new HttpError(409,'Pagamento PayPal já associado a outra subscrição.');
                    return;
                }
                $this->db->insert('paypal_subscription_payments',['id'=>$saleId,'intent_id'=>$intent['id'],'amount_cents'=>$intent['amount_cents']]);
            }
            $current=$this->db->one('SELECT covered_until FROM paypal_intents WHERE id=?',[$intent['id']]);
            $covered=max($current['covered_until']??'',$until??'')?:null;
            $this->db->run('UPDATE paypal_intents SET status=?,covered_until=? WHERE id=?',[$status,$covered,$intent['id']]);
            if ($sale&&$status==='ACTIVE') {
                $this->db->run("UPDATE companies SET plan_id=?,subscription_status='paypal_sandbox' WHERE id=?",[$intent['plan_id'],$intent['company_id']]);
            }
        });
    }

    private static function euros(int $amount): string {
        return intdiv($amount,100).'.'.str_pad((string)($amount%100),2,'0',STR_PAD_LEFT);
    }

    private static function amountMatches(array $amount,int $expected): bool {
        $value=$amount['value']??null;
        if (($amount['currency_code']??'')!=='EUR'||!is_string($value)||!preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D',$value,$match)) return false;
        return (int)$match[1]*100+(int)str_pad($match[2]??'',2,'0')===$expected;
    }
}
