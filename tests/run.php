<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/database/seed.php';
use HomeAlone\{Database,Scope,Rentals,Finance,Operations,Payments,Jobs,Administration,Documents,Auth,Application,HttpError};
$passed=0;$failed=0;
function test(string $name,callable $fn):void {global $passed,$failed;try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()." @ ".basename($e->getFile()).':'.$e->getLine()."\n";}}
function eq(mixed $actual,mixed $expected):void{if($actual!==$expected)throw new RuntimeException('Expected '.json_encode($expected).', got '.json_encode($actual));}
function ok(bool $condition,string $message='Assertion failed'):void{if(!$condition)throw new RuntimeException($message);}
function rejects(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){eq($e->status,$status);return;}throw new RuntimeException('Expected HTTP '.$status);}
if(getenv('RENDORA_TRIAL_MYSQL_DSN')){
    require dirname(__DIR__).'/scripts/mysql-demo-connection.php';
    $db=new Database(mysqlDemoConnection());
    if($db->one('SELECT id FROM users LIMIT 1'))throw new RuntimeException('Use uma base de ensaio vazia.');
}else{
    $db=new Database(':memory:');
    $db->migrate();
}
seed($db);
$s=new Scope($db,'company-lisbon',['id'=>'u-admin','name'=>'Mariana'],'ADMIN');$resident=new Scope($db,'company-lisbon',['id'=>'u-resident'],'RESIDENT');$tech=new Scope($db,'company-lisbon',['id'=>'u-tech'],'TECHNICIAN');$owner=new Scope($db,'company-lisbon',['id'=>'u-owner'],'OWNER');$porto=new Scope($db,'company-porto',['id'=>'u-porto'],'ADMIN');
$r=new Rentals($db);$f=new Finance($db);$o=new Operations($db);$p=new Payments($db);$ad=new Administration($db);
$base=['property_id'=>'p-graca','contact_ids'=>['co-beatriz'],'start_date'=>'2030-01-01','end_date'=>'2030-12-31','rent_cents'=>100000,'charges_cents'=>0,'deposit_cents'=>200000,'due_day'=>31,'notes'=>'Teste'];
test('Tenant property lists are isolated',function()use($r,$s,$porto){eq(count($r->properties($porto)),1);ok(!in_array('p-porto',array_column($r->properties($s),'id')));});
test('Tenant IDs cannot bypass scope',fn()=>rejects(fn()=>$s->property('p-porto'),404));
test('Tenant contract association rejected',fn()=>rejects(fn()=>$r->createContract($s,[...$base,'property_id'=>'p-porto']),404));
test('Residents cannot view another contract',fn()=>rejects(fn()=>$resident->contract('ct-ana'),404));
test('Resident sees only own charges',function()use($f,$resident){foreach($f->charges($resident)as $c)eq($c['contract_id'],'ct-ines');});
test('Technician cannot read financial data',fn()=>rejects(fn()=>$tech->contract('ct-ines'),404));
test('Technician cannot inspect unassigned ticket',fn()=>rejects(fn()=>$tech->ticket('t-light'),404));
test('Residents do not see internal notes',function()use($o,$resident,$s){eq(count($o->ticket($resident,'t-water')['notes']),1);eq(count($o->ticket($s,'t-water')['notes']),2);});
test('Resident cannot create internal note',fn()=>rejects(fn()=>$o->note($resident,'t-water',['body'=>'Teste','internal'=>true]),403));
test('Create valid monthly contract',function()use($r,$s,$base){$c=$r->createContract($s,$base);eq($c['rent_cents'],100000);});
test('Overlapping contracts are rejected',fn()=>rejects(fn()=>$r->createContract($s,$base),409));
test('Boundary day overlap rejected',fn()=>rejects(fn()=>$r->createContract($s,[...$base,'start_date'=>'2030-12-31','end_date'=>'2031-12-31']),409));
test('Next-day contract permitted',function()use($r,$s,$base){$c=$r->createContract($s,[...$base,'start_date'=>'2031-01-01','end_date'=>'2031-12-31']);ok((bool)$c['id']);});
test('Invalid dates rejected',fn()=>rejects(fn()=>$r->createContract($s,[...$base,'start_date'=>'2032-02-31']),422));
test('Apartment / room overlap blocked in both directions',function()use($db,$r,$s,$base){$db->insert('properties',['id'=>'test-room','company_id'=>$s->companyId,'name'=>'Quarto A','type'=>'ROOM','parent_id'=>'p-graca','address'=>'Rua de teste','city'=>'Lisboa','rent_cents'=>50000]);rejects(fn()=>$r->createContract($s,[...$base,'property_id'=>'test-room']),409);$r->createContract($s,[...$base,'property_id'=>'test-room','start_date'=>'2033-01-01','end_date'=>'2033-12-31']);rejects(fn()=>$r->createContract($s,[...$base,'start_date'=>'2033-01-01','end_date'=>'2033-12-31']),409);});
test('Separate rooms can be occupied simultaneously',function()use($db,$r,$s,$base){$db->insert('properties',['id'=>'test-room-2','company_id'=>$s->companyId,'name'=>'Quarto B','type'=>'ROOM','parent_id'=>'p-graca','address'=>'Rua de teste','city'=>'Lisboa','rent_cents'=>50000]);$c=$r->createContract($s,[...$base,'property_id'=>'test-room-2','start_date'=>'2033-01-01','end_date'=>'2033-12-31']);ok((bool)$c['id']);});
test('Prorating leap month and due-day clamping',function()use($base){$v=Finance::monthlyAmount([...$base,'start_date'=>'2028-02-15','end_date'=>'2028-02-29','rent_cents'=>290000],'2028-02');eq($v['amount_cents'],150000);eq($v['due_date'],'2028-02-29');eq($v['confirmed'],false);});
test('Future rent change applies by effective day',function()use($base){$v=Finance::monthlyAmount([...$base,'start_date'=>'2030-01-01','end_date'=>null,'rent_cents'=>310000],'2030-01',[['effective_date'=>'2030-01-16','rent_cents'=>620000]]);eq($v['amount_cents'],470000);});
test('Monthly generation is idempotent',function()use($f,$s){$first=$f->generate($s,'2030-02');ok($first['created']>0);eq($f->generate($s,'2030-02')['created'],0);});
$charge=$db->one("SELECT * FROM charges WHERE period='2030-02' LIMIT 1");
test('Partial manual payment and retry are idempotent',function()use($f,$s,$charge){$d=['amount_cents'=>30000,'kind'=>'PAYMENT','reason'=>'Transferência teste','idempotency_key'=>'manual-test-1'];$a=$f->manual($s,$charge['id'],$d);$b=$f->manual($s,$charge['id'],$d);eq($a['id'],$b['id']);eq($f->balance($charge['id'])['status'],'PARTIAL');eq($f->balance($charge['id'])['balance_cents'],70000);});
test('Reusing idempotency key with different amount fails',fn()=>rejects(fn()=>$f->manual($s,$charge['id'],['amount_cents'=>1,'kind'=>'PAYMENT','reason'=>'Teste alteração','idempotency_key'=>'manual-test-1']),409));
test('Payment exceeding balance rejected',fn()=>rejects(fn()=>$f->manual($s,$charge['id'],['amount_cents'=>999999,'kind'=>'PAYMENT','reason'=>'Valor incorreto','idempotency_key'=>'too-large-1']),422));
test('Resident cannot manually settle rent',fn()=>rejects(fn()=>$f->manual($resident,$charge['id'],[]),403));
test('Deposits cannot be over-refunded',fn()=>rejects(fn()=>$r->deposit($s,'ct-ines',['kind'=>'RETURNED','amount_cents'=>999999,'reason'=>'Devolução']),422));
test('Deposits do not alter rental balance',function()use($r,$s,$f,$charge){$before=$f->balance($charge['id']);$r->deposit($s,'ct-ines',['kind'=>'DEDUCTED','amount_cents'=>5000,'reason'=>'Reparação documentada']);eq($f->balance($charge['id']),$before);});
test('Public catalog excludes unpublished records and private data',function()use($ad){$public=$ad->publicCompany('casa-lisboa');ok(!in_array('p-building',array_column($public['properties'],'id')));foreach($public['properties']as $item){ok(!isset($item['owner_id']));ok(!isset($item['resident_ids']));}ok(!isset($public['company']['stripe_account_id']));});
test('Full lead to contract path',function()use($ad,$o,$s,$db,$base){$ad->publicLead('casa-lisboa',['property_id'=>'p-alcantara','name'=>'Pessoa de Teste','email'=>'person@example.test','phone'=>'','notes'=>'Quero visitar','consent'=>true],'test-ip');$lead=$db->one("SELECT * FROM leads WHERE contact_id IN(SELECT id FROM contacts WHERE email='person@example.test')");$o->updateLead($s,$lead['id'],['stage'=>'VISIT','visit_at'=>'2034-01-01','task'=>'Visita']);$o->updateLead($s,$lead['id'],['stage'=>'PROPOSAL']);$contract=$o->convert($s,$lead['id'],[...$base,'property_id'=>'p-alcantara','start_date'=>'2034-01-01','end_date'=>'2034-12-31']);eq($contract['property_id'],'p-alcantara');eq($db->one('SELECT stage FROM leads WHERE id=?',[$lead['id']])['stage'],'CONVERTED');rejects(fn()=>$o->convert($s,$lead['id'],$base),409);});
test('Consent required on public lead',fn()=>rejects(fn()=>$ad->publicLead('casa-lisboa',['consent'=>false],'test-consent'),422));
test('Ticket lifecycle including resolution and reopening',function()use($o,$s,$resident,$tech){$t=$o->createTicket($resident,['property_id'=>'p-estrela','title'=>'Teste do pedido','description'=>'Descrição do problema para teste','category'=>'OTHER','priority'=>'NORMAL']);$o->updateTicket($s,$t['id'],['assignee_id'=>'u-tech','status'=>'SCHEDULED']);$o->updateTicket($tech,$t['id'],['status'=>'RESOLVED','note'=>'Equipamento reparado e testado.']);eq($o->reopen($resident,$t['id'])['status'],'TRIAGE');});
test('Foreign assignee rejected',fn()=>rejects(fn()=>$o->updateTicket($s,'t-water',['assignee_id'=>'u-porto']),422));
test('Conversation participants control access',function()use($o,$s,$resident,$tech){$c=$o->createConversation($s,['subject'=>'Conversa teste','participant_ids'=>['u-resident'],'body'=>'Olá']);eq(count($o->messages($resident,$c['id'])),1);rejects(fn()=>$o->messages($tech,$c['id']),404);$o->markRead($resident,$c['id']);eq($o->messages($s,$c['id'])[0]['read_count'],1);});
test('Foreign conversation recipient rejected',fn()=>rejects(fn()=>$o->createConversation($s,['subject'=>'Teste','participant_ids'=>['u-porto']]),422));
test('Notification enqueue is idempotent',function()use($o,$db){$o->notify('company-lisbon','u-resident','Título','Mensagem','test-notification');$o->notify('company-lisbon','u-resident','Título','Mensagem','test-notification');eq($db->one("SELECT COUNT(*) n FROM notifications WHERE dedupe_key='test-notification'")['n'],1);eq($db->one("SELECT COUNT(*) n FROM jobs WHERE dedupe_key='email:test-notification'")['n'],1);});
$db->run("UPDATE companies SET stripe_account_id='acct_test' WHERE id='company-lisbon'");$co=$db->insert('checkouts',['company_id'=>'company-lisbon','charge_id'=>$charge['id'],'amount_cents'=>70000,'expires_at'=>'2030-03-01 00:00:00']);
$event=fn($id,$type,$object)=>['id'=>$id,'account'=>'acct_test','type'=>$type,'data'=>['object'=>$object]];
$object=['id'=>'pi_test','metadata'=>['checkout_id'=>$co['id']],'amount_received'=>70000,'currency'=>'eur'];
test('Verified payment event settles and duplicates do not double-credit',function()use($p,$event,$object,$f,$charge){$p->applyEvent($event('evt_payment','payment_intent.succeeded',$object));$p->applyEvent($event('evt_payment','payment_intent.succeeded',$object));$p->applyEvent($event('evt_payment_second','payment_intent.succeeded',$object));eq($f->balance($charge['id'])['paid_cents'],100000);eq($f->balance($charge['id'])['status'],'PAID');});
test('Late failure cannot regress a successful checkout',function()use($p,$event,$co,$db){$p->applyEvent($event('evt_late_failure','checkout.session.async_payment_failed',['id'=>'cs_test','metadata'=>['checkout_id'=>$co['id']]]));eq($db->one('SELECT status FROM checkouts WHERE id=?',[$co['id']])['status'],'SUCCEEDED');});
test('Refund duplicate events debit once',function()use($p,$event,$f,$charge){$refund=['id'=>'re_test','payment_intent'=>'pi_test','status'=>'succeeded','amount'=>10000];$p->applyEvent($event('evt_refund','refund.created',$refund));$p->applyEvent($event('evt_refund_update','refund.updated',$refund));eq($f->balance($charge['id'])['paid_cents'],90000);});
test('Out-of-order dispute win preserves net balance',function()use($p,$event,$f,$charge){$dispute=['id'=>'dp_test','payment_intent'=>'pi_test','amount'=>20000,'status'=>'won'];$p->applyEvent($event('evt_dispute_closed','charge.dispute.closed',$dispute));$p->applyEvent($event('evt_dispute_created','charge.dispute.created',$dispute));eq($f->balance($charge['id'])['paid_cents'],90000);});
test('Unexpected currency rolls back event',function()use($p,$event,$object,$db){rejects(fn()=>$p->applyEvent($event('evt_bad_currency','payment_intent.succeeded',[...$object,'currency'=>'usd'])),400);eq($db->one("SELECT id FROM webhook_events WHERE id='evt_bad_currency'"),null);});
test('Invalid webhook signature never settles rent',function()use($p){$_ENV['STRIPE_WEBHOOK_SECRET']='whsec_test_only';rejects(fn()=>$p->webhook('{}','invalid'),400);unset($_ENV['STRIPE_WEBHOOK_SECRET']);});
test('Online payments report missing configuration honestly',function()use($p,$s,$charge){$_ENV['STRIPE_SECRET_KEY']='';rejects(fn()=>$p->checkout($s,$charge['id']),503);});
test('Paid reminder is cancelled at send time',function()use($db,$o,$f,$s){$c=$db->one("SELECT * FROM charges WHERE contract_id='ct-ines' LIMIT 1");$o->notify('company-lisbon','u-resident','Renda','Pagar','paid-reminder',$c['id']);$db->run("UPDATE jobs SET status='DONE' WHERE dedupe_key<>'email:paid-reminder'");$sent=0;(new Jobs($db))->work(10,function()use(&$sent){$sent++;});eq($sent,0);eq($db->one("SELECT status FROM jobs WHERE dedupe_key='email:paid-reminder'")['status'],'CANCELLED');});
test('Failed email retries and enters failure queue',function()use($db,$o){$o->notify('company-lisbon','u-resident','Teste','Mensagem','failure-test');$jobs=new Jobs($db);for($i=0;$i<5;$i++){$db->run("UPDATE jobs SET available_at='2000-01-01' WHERE dedupe_key='email:failure-test'");$jobs->work(1,function(){throw new RuntimeException('offline');});}eq($db->one("SELECT status FROM jobs WHERE dedupe_key='email:failure-test'")['status'],'FAILED');});
test('Private documents inherit entity authorization',function()use($db,$s,$porto,$resident){$docs=new Documents($db);$docs->authorize($resident,'contract','ct-ines');rejects(fn()=>$docs->authorize($resident,'contract','ct-ana'),404);rejects(fn()=>$docs->authorize($porto,'ticket','t-water'),404);rejects(fn()=>$docs->authorize($resident,'contract','ct-ines',true),403);});
test('Database foreign keys prevent cross-company references',function()use($db){try{$db->insert('leads',['company_id'=>'company-porto','contact_id'=>'co-ines']);}catch(PDOException){return;}throw new RuntimeException('FK not enforced');});
test('Public and authenticated API paths respect session boundaries',function()use($db){$app=Application::create($db);$factory=new \Slim\Psr7\Factory\ServerRequestFactory();$res=$app->handle($factory->createServerRequest('GET','/api/v1/properties'));eq($res->getStatusCode(),401);$session=(new Auth($db))->session($db->one("SELECT * FROM users WHERE id='u-resident'"));$req=$factory->createServerRequest('GET','/api/v1/contracts')->withHeader('Authorization','Bearer '.$session['token'])->withHeader('X-Company-Id','company-porto');eq($app->handle($req)->getStatusCode(),403);$req=$req->withHeader('X-Company-Id','company-lisbon');$res=$app->handle($req);eq($res->getStatusCode(),200);$rows=json_decode((string)$res->getBody(),true);eq(count($rows),1);eq($rows[0]['id'],'ct-ines');});
test('Cookie authentication requires CSRF for writes',function()use($db){$session=(new Auth($db))->session($db->one("SELECT * FROM users WHERE id='u-admin'"));$req=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/api/v1/charges/generate')->withCookieParams(['ha_session'=>$session['token']])->withHeader('X-Company-Id','company-lisbon')->withParsedBody(['period'=>'2030-04']);$app=Application::create($db);eq($app->handle($req)->getStatusCode(),403);eq($app->handle($req->withHeader('X-CSRF-Token',$session['csrf']))->getStatusCode(),200);});
if($db->engine()==='sqlite')test('SQLite backup restores exact records and relationships',function()use($db){$dir=dirname(__DIR__).'/storage/test-backups';if(!is_dir($dir))mkdir($dir,0700,true);$path=$dir.'/'.Database::id().'.sqlite';$db->pdo->exec('VACUUM INTO '.$db->pdo->quote($path));$restored=new Database($path);eq(array_values($restored->one('PRAGMA integrity_check'))[0],'ok');eq($restored->all('PRAGMA foreign_key_check'),[]);eq($restored->one('SELECT COUNT(*) n FROM payments'),$db->one('SELECT COUNT(*) n FROM payments'));unset($restored);unlink($path);});
test('Email possession is required before a pending invitation grants access',function()use($db,$ad,$s){$u=$db->insert('users',['name'=>'Unverified user','email'=>'unverified@example.test','password_hash'=>password_hash('TestPassword123!',PASSWORD_DEFAULT)]);$ad->invite($s,['name'=>'Unverified user','email'=>'unverified@example.test','role'=>'RESIDENT']);$m=$db->one('SELECT * FROM memberships WHERE user_id=?',[$u['id']]);eq($m['active'],0);eq($m['invitation_pending'],1);rejects(fn()=>$ad->updateMember($s,$u['id'],['role'=>'RESIDENT','active'=>true]),409);$job=$db->one("SELECT payload FROM jobs WHERE type='EMAIL' AND payload LIKE '%unverified@example.test%'");$body=json_decode($job['payload'],true)['body'];$token=substr($body,strrpos($body,'=')+1);(new Auth($db))->reset(['token'=>$token,'password'=>'NewTestPassword123!']);eq($db->one('SELECT active FROM memberships WHERE user_id=?',[$u['id']])['active'],1);ok((bool)$db->one('SELECT email_verified_at FROM users WHERE id=?',[$u['id']])['email_verified_at']);$db->run('UPDATE memberships SET active=0,invitation_pending=0 WHERE user_id=?',[$u['id']]);(new Auth($db))->resetRequest(['email'=>'unverified@example.test'],'reset-second-test');$jobs=$db->all("SELECT payload FROM jobs WHERE type='EMAIL' AND payload LIKE '%unverified@example.test%' AND dedupe_key<>?",['reset:'.hash('sha256',$token)]);$body=json_decode($jobs[0]['payload'],true)['body'];$token=substr($body,strrpos($body,'=')+1);(new Auth($db))->reset(['token'=>$token,'password'=>'ThirdTestPassword123!']);eq($db->one('SELECT active FROM memberships WHERE user_id=?',[$u['id']])['active'],0);});
test('Unpaid future charges are recalculated after a rent change',function()use($db,$s,$r,$f){$contract=$db->one("SELECT * FROM contracts WHERE property_id='p-graca' AND start_date='2030-01-01'");$f->generate($s,'2030-03');$r->changeContract($s,$contract['id'],['action'=>'rent','effective_date'=>'2030-03-16','rent_cents'=>200000]);eq($db->one("SELECT amount_cents FROM charges WHERE contract_id=? AND period='2030-03'",[$contract['id']])['amount_cents'],151613);rejects(fn()=>$r->changeContract($s,$contract['id'],['action'=>'rent','effective_date'=>'2030-02-01','rent_cents'=>200000]),409);});
$platformActor=$db->one("SELECT * FROM users WHERE id='u-admin'");
$companyInput=['name'=>'Empresa independente','slug'=>'empresa-independente','email'=>'equipa@independente.test','admin_name'=>'Administrador Novo','admin_email'=>'admin@independente.test'];
test('Only platform admins can create companies or inspect administrators',function()use($ad,$db,$companyInput){$actor=$db->one("SELECT * FROM users WHERE id='u-porto'");rejects(fn()=>$ad->createCompany($actor,$companyInput),403);rejects(fn()=>$ad->platform($actor),403);rejects(fn()=>$ad->resendCompanyInvite($actor,'company-lisbon','u-admin'),403);});
test('New company is empty, isolated and pending until administrator verifies email',function()use($ad,$db,$platformActor,$companyInput,$r){
    $c=$ad->createCompany($platformActor,$companyInput);$id=$c['id'];$uid=$c['administrator']['id'];
    eq($c['administrator']['active'],0);eq($c['administrator']['invitation_pending'],1);
    eq($db->one('SELECT COUNT(*) n FROM memberships WHERE company_id=?',[$id])['n'],1);
    eq($db->one('SELECT COUNT(*) n FROM properties WHERE company_id=?',[$id])['n'],0);
    eq($db->one('SELECT COUNT(*) n FROM contracts WHERE company_id=?',[$id])['n'],0);
    eq($db->one('SELECT platform_admin FROM users WHERE id=?',[$uid])['platform_admin'],0);
    ok((bool)$db->one("SELECT id FROM audit WHERE company_id=? AND user_id='u-admin' AND action='platform.company_created'",[$id]));
    $auth=new Auth($db);$session=$auth->session($db->one('SELECT * FROM users WHERE id=?',[$uid]));
    $req=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET','/api/v1/properties')->withHeader('Authorization','Bearer '.$session['token'])->withHeader('X-Company-Id',$id);
    rejects(fn()=>$auth->scope($req),403);
    $job=$db->one("SELECT payload FROM jobs WHERE type='EMAIL' AND payload LIKE '%admin@independente.test%'");$body=json_decode($job['payload'],true)['body'];$token=substr($body,strrpos($body,'=')+1);
    $auth->reset(['token'=>$token,'password'=>'TestNewCompany123!']);
    $session=$auth->session($db->one('SELECT * FROM users WHERE id=?',[$uid]));$req=$req->withHeader('Authorization','Bearer '.$session['token']);
    $scope=$auth->scope($req);eq($scope->role,'ADMIN');eq($r->properties($scope),[]);
    rejects(fn()=>$auth->scope($req->withHeader('X-Company-Id','company-lisbon')),403);
    rejects(fn()=>$scope->property('p-porto'),404);
});
test('Existing verified administrator keeps identity password and other memberships',function()use($ad,$db,$platformActor,$companyInput){
    $db->run("UPDATE users SET email_verified_at=CURRENT_TIMESTAMP WHERE id='u-porto'");$before=$db->one("SELECT * FROM users WHERE id='u-porto'");
    $c=$ad->createCompany($platformActor,[...$companyInput,'slug'=>'segunda-empresa','admin_name'=>'Nome que não deve substituir','admin_email'=>$before['email']]);
    eq($c['administrator']['id'],'u-porto');eq($c['administrator']['active'],1);eq($c['administrator']['name'],$before['name']);
    eq($db->one("SELECT password_hash FROM users WHERE id='u-porto'")['password_hash'],$before['password_hash']);
    ok((bool)$db->one("SELECT id FROM memberships WHERE user_id='u-porto' AND company_id='company-porto' AND active=1"));
    eq($db->one("SELECT COUNT(*) n FROM memberships WHERE company_id=? AND user_id='u-admin'",[$c['id']])['n'],0);
});
test('Invalid or duplicate company input creates no partial company or user',function()use($ad,$db,$platformActor,$companyInput){
    $counts=$db->one('SELECT (SELECT COUNT(*) FROM companies) companies,(SELECT COUNT(*) FROM users) users');
    rejects(fn()=>$ad->createCompany($platformActor,[...$companyInput,'slug'=>'INVALID SLUG']),422);
    rejects(fn()=>$ad->createCompany($platformActor,[...$companyInput,'admin_email'=>'invalid']),422);
    rejects(fn()=>$ad->createCompany($platformActor,[...$companyInput,'admin_email'=>'must-not-exist@example.test']),409);
    eq($db->one('SELECT (SELECT COUNT(*) FROM companies) companies,(SELECT COUNT(*) FROM users) users'),$counts);
});
test('Pending admin invite can be resent only for the matching company and user',function()use($ad,$db,$platformActor,$companyInput){
    $c=$ad->createCompany($platformActor,[...$companyInput,'slug'=>'empresa-convite','admin_email'=>'resend-admin@example.test']);$uid=$c['administrator']['id'];
    $old=$db->one('SELECT token_hash FROM reset_tokens WHERE user_id=?',[$uid]);
    eq($ad->resendCompanyInvite($platformActor,$c['id'],$uid),['queued'=>true]);
    ok($db->one('SELECT token_hash FROM reset_tokens WHERE user_id=?',[$uid])!==$old);
    rejects(fn()=>$ad->resendCompanyInvite($platformActor,'company-lisbon',$uid),404);
    eq($db->one('SELECT active FROM memberships WHERE company_id=? AND user_id=?',[$c['id'],$uid])['active'],0);
});
test('Company administrator membership audit and queued invite roll back together',function()use($ad,$db,$platformActor,$companyInput){
    $counts=$db->one('SELECT (SELECT COUNT(*) FROM companies) c,(SELECT COUNT(*) FROM users) u,(SELECT COUNT(*) FROM jobs) j,(SELECT COUNT(*) FROM memberships) m,(SELECT COUNT(*) FROM reset_tokens) t');
    $db->pdo->exec($db->engine()==='mysql'
        ?"CREATE TRIGGER fail_platform_audit BEFORE INSERT ON audit FOR EACH ROW BEGIN IF NEW.action='platform.company_created' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test failure'; END IF; END"
        :"CREATE TEMP TRIGGER fail_platform_audit BEFORE INSERT ON audit WHEN NEW.action='platform.company_created' BEGIN SELECT RAISE(ABORT,'test failure'); END");
    try {try{$ad->createCompany($platformActor,[...$companyInput,'slug'=>'rollback-company','admin_email'=>'rollback@example.test']);throw new RuntimeException('Expected failure');}catch(PDOException){} }finally{$db->pdo->exec('DROP TRIGGER fail_platform_audit');}
    eq($db->one('SELECT (SELECT COUNT(*) FROM companies) c,(SELECT COUNT(*) FROM users) u,(SELECT COUNT(*) FROM jobs) j,(SELECT COUNT(*) FROM memberships) m,(SELECT COUNT(*) FROM reset_tokens) t'),$counts);
});
echo "\n$passed passed; $failed failed.\n";exit($failed?1:0);
