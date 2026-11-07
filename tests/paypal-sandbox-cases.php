<?php
declare(strict_types=1);

use HomeAlone\{BankTransfers,Database,Finance,PayPalSandbox,PayPalSandboxClient,Scope};

test('PayPal Sandbox verifies recipient, capture and initial and renewal events through the verification path without duplicate credits',function()use($db,$s,$resident,$porto){
    $settings=['APP_ENV'=>'test','ALLOW_LIVE_PAYMENTS'=>'false','PAYPAL_SANDBOX_ENABLED'=>'true','BANK_TRANSFERS_ENABLED'=>'true','APP_URL'=>'https://example.test'];
    $prior=[];
    foreach ($settings as $key=>$value) {$prior[$key]=getenv($key);putenv("$key=$value");}
    try {
        $period=(new DateTimeImmutable(Database::today()))->modify('first day of +4 months')->format('Y-m');
        $finance=new Finance($db);
        $finance->generate($s,$period);
        $charge=$db->one("SELECT * FROM charges WHERE contract_id='ct-ines' AND period=?",[$period]);
        ok($charge!==null);
        $id=$charge['id'];
        $bank=new BankTransfers($db);
        $bank->setRentAccount($s,'ct-ines',['recipient_id'=>'u-owner','beneficiary'=>'Beneficiário de teste','iban'=>'PT50000000000000000000001']);
        $plan='plan-demo';
        $merchant='BENEFICIARY99';
        $apps=[
            'subscriptions'=>['client_id'=>'test-sub','client_secret'=>'synthetic','webhook_id'=>'hook-sub','merchant_id'=>'RENDORETEST99',
                'plans'=>[['plan_id'=>$plan,'paypal_plan_id'=>'P-SANDBOX-TEST','months'=>1,'amount_cents'=>1900]]],
            'rent-test'=>['client_id'=>'test-rent','client_secret'=>'synthetic','webhook_id'=>'hook-rent','merchant_id'=>$merchant,
                'company_id'=>'company-lisbon','recipient_id'=>'u-owner'],
        ];
        $order=[];$sub=[];$captured=false;$signature='SUCCESS';$captureCalls=0;$expectedRaw=null;
        $next=(new DateTimeImmutable('+1 month'))->format(DATE_ATOM);
        $client=new PayPalSandboxClient($apps,function($method,$url,$headers,$body)use(&$order,&$sub,&$captured,&$signature,&$captureCalls,&$next,&$expectedRaw,$merchant){
            eq(parse_url($url,PHP_URL_HOST),'api-m.sandbox.paypal.com');
            $path=parse_url($url,PHP_URL_PATH);
            $input=$body&&$body!=='grant_type=client_credentials'?json_decode($body,true):[];
            if($path==='/v1/oauth2/token')return ['access_token'=>'test-token'];
            if($path==='/v1/notifications/verify-webhook-signature'){
                ok(in_array($input['webhook_id'],['hook-rent','hook-sub'],true));
                eq($input['transmission_sig'],'test-signature');
                if ($expectedRaw!==null) ok(str_contains($body,'"webhook_event":'.$expectedRaw));
                return ['verification_status'=>$signature];
            }
            if($path==='/v2/checkout/orders'){
                eq($input['purchase_units'][0]['payee']['merchant_id'],$merchant);
                $order=['id'=>'ORDER-FIXTURE','status'=>'APPROVED','purchase_units'=>$input['purchase_units']];
                return [...$order,'links'=>[['rel'=>'payer-action','href'=>'https://www.sandbox.paypal.com/checkoutnow?token=fixture']]];
            }
            if($path==='/v2/checkout/orders/ORDER-FIXTURE/capture'){
                $captureCalls++;
                $captured=true;
                return ['status'=>'COMPLETED'];
            }
            if($path==='/v2/checkout/orders/ORDER-FIXTURE'){
                if(!$captured)return $order;
                $unit=$order['purchase_units'][0];
                $unit['payments']=['captures'=>[['id'=>'CAPTURE-FIXTURE','status'=>'COMPLETED','amount'=>$unit['amount']]]];
                return [...$order,'status'=>'COMPLETED','purchase_units'=>[$unit]];
            }
            if($path==='/v1/billing/plans/P-SANDBOX-TEST')return [
                'status'=>'ACTIVE','billing_cycles'=>[['tenure_type'=>'REGULAR','frequency'=>['interval_unit'=>'MONTH','interval_count'=>1],
                    'pricing_scheme'=>['fixed_price'=>['currency_code'=>'EUR','value'=>'19.00']]]],
            ];
            if($path==='/v1/billing/subscriptions'){
                $sub=['id'=>'I-SANDBOX-FIXTURE','plan_id'=>$input['plan_id'],'custom_id'=>$input['custom_id'],'status'=>'ACTIVE'];
                return [...$sub,'links'=>[['rel'=>'approve','href'=>'https://www.sandbox.paypal.com/webapps/billing/subscriptions?fixture=1']]];
            }
            if($path==='/v1/billing/subscriptions/I-SANDBOX-FIXTURE')return [...$sub,'billing_info'=>['next_billing_time'=>$next]];
            throw new RuntimeException('Unexpected PayPal fixture route '.$method.' '.$path);
        });
        $paypal=new PayPalSandbox($db,$client);
        $headers=['auth_algo'=>'SHA256withRSA','cert_url'=>'https://api.sandbox.paypal.com/cert','transmission_id'=>'test-transmission',
            'transmission_sig'=>'test-signature','transmission_time'=>'2026-01-01T00:00:00Z'];
        $event=static fn($id,$type,$resource)=>['id'=>$id,'event_type'=>$type,'resource'=>$resource];
        rejects(fn()=>$paypal->startRent($porto,$id),403);
        eq($paypal->rent($resident,$id)['beneficiary'],'Beneficiário de teste');
        ok(str_contains($paypal->startRent($resident,$id)['url'],'sandbox.paypal.com'));
        rejects(fn()=>$finance->manual($s,$id,['amount_cents'=>100,'reason'=>'Fixture','kind'=>'PAYMENT','idempotency_key'=>'blocked-by-sandbox']),409);
        $approved=$event('evt-order-approve','CHECKOUT.ORDER.APPROVED',['id'=>'ORDER-FIXTURE']);
        $signature='FAILURE';
        rejects(fn()=>$paypal->webhook('rent-test',$approved,$headers),400);
        $signature='SUCCESS';
        $order['purchase_units'][0]['payee']['merchant_id']='WRONG-MERCHANT';
        rejects(fn()=>$paypal->webhook('rent-test',$approved,$headers),409);
        eq($captureCalls,0);
        $order['purchase_units'][0]['payee']['merchant_id']=$merchant;
        $expectedRaw=json_encode($approved,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        eq($paypal->webhook('rent-test',$approved,$headers,$expectedRaw),['received'=>true]);
        $expectedRaw=null;
        eq($captureCalls,1);
        $completed=$event('evt-capture-complete','PAYMENT.CAPTURE.COMPLETED',
            ['id'=>'CAPTURE-FIXTURE','supplementary_data'=>['related_ids'=>['order_id'=>'ORDER-FIXTURE']]]);
        $paypal->webhook('rent-test',$completed,$headers);
        $paypal->webhook('rent-test',$completed,$headers);
        eq((int)$db->one("SELECT COUNT(*) n FROM payments WHERE external_id='CAPTURE-FIXTURE'")['n'],1);
        eq($finance->balance($id)['balance_cents'],0);

        ok(str_contains($paypal->startSubscription($s,['plan_id'=>$plan,'months'=>1])['url'],'sandbox.paypal.com'));
        $paypal->webhook('subscriptions',$event('evt-sub-activated','BILLING.SUBSCRIPTION.ACTIVATED',['id'=>$sub['id']]),$headers);
        eq($paypal->subscription($s)['payment']['covered_until'],null);
        $sale=fn($id)=>['id'=>$id,'billing_agreement_id'=>$sub['id'],'state'=>'completed','amount'=>['currency'=>'EUR','total'=>'19.00']];
        rejects(fn()=>$paypal->webhook('subscriptions',$event('evt-sub-wrong','PAYMENT.SALE.COMPLETED',[...$sale('SALE-WRONG'),'amount'=>['currency'=>'USD','total'=>'19.00']]),$headers),409);
        $paypal->webhook('subscriptions',$event('evt-sub-initial','PAYMENT.SALE.COMPLETED',$sale('SALE-INITIAL')),$headers);
        $first=$paypal->subscription($s)['payment']['covered_until'];
        ok(is_string($first)&&$first!=='');
        $next=(new DateTimeImmutable('+2 months'))->format(DATE_ATOM);
        $paypal->webhook('subscriptions',$event('evt-sub-initial-duplicate','PAYMENT.SALE.COMPLETED',$sale('SALE-INITIAL')),$headers);
        eq($paypal->subscription($s)['payment']['covered_until'],$first);
        $paypal->webhook('subscriptions',$event('evt-sub-renewal','PAYMENT.SALE.COMPLETED',$sale('SALE-RENEWAL')),$headers);
        ok($paypal->subscription($s)['payment']['covered_until']>$first);
        eq((int)$db->one('SELECT COUNT(*) n FROM paypal_subscription_payments')['n'],2);
        eq($db->one("SELECT subscription_status FROM companies WHERE id='company-lisbon'")['subscription_status'],'paypal_sandbox');
        putenv('ALLOW_LIVE_PAYMENTS=true');
        rejects(fn()=>$paypal->subscription($s),404);
        putenv('ALLOW_LIVE_PAYMENTS=false');
        putenv('APP_ENV=production');
        rejects(fn()=>$paypal->subscription($s),404);
    } finally {
        foreach ($prior as $key=>$value) putenv($value===false?$key:"$key=$value");
    }
});
