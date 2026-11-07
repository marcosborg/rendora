<?php
declare(strict_types=1);
namespace HomeAlone;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
final class Application {
    public static function create(Database $db): \Slim\App {
        $app=AppFactory::create();$auth=new Auth($db);$rentals=new Rentals($db);$finance=new Finance($db);$ops=new Operations($db);$admin=new Administration($db);$payments=new Payments($db);$docs=new Documents($db);$bank=new BankTransfers($db);$paypal=new PayPalSandbox($db);
        $json=static function(Response $r,mixed $data,int $status=200):Response{$r->getBody()->write(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return $r->withStatus($status)->withHeader('Content-Type','application/json; charset=utf-8')->withHeader('Cache-Control','no-store');};
        $body=static function(Request $r):array{$data=$r->getParsedBody();if(!is_array($data))return [];return $data;};
        $route=static function(string $method,string $path,callable $handler)use($app,$auth,$json,$body){$app->map([$method],'/api/v1'.$path,function(Request $r,Response $response,array $args)use($handler,$auth,$json,$body){$scope=$auth->scope($r);$result=$handler($scope,$body($r),$args,$r);return $result instanceof Response?$result:$json($response,$result??['ok'=>true]);});};
        $app->get('/api/v1/health',fn(Request $r,Response $res)=>$json($res,['status'=>$db->one('SELECT 1 ok')['ok']?'ok':'error','storage'=>$db->engine(),...(defined('RENDORA_PUBLIC_DEMO')?[$db->engine().'_version'=>$db->one($db->engine()==='mysql'?'SELECT VERSION() version':'SELECT sqlite_version() version')['version']]:[])]));
        $app->get('/api/v1/config',fn(Request $r,Response $res)=>$json($res,['demo'=>env('DEMO_MODE')==='true'&&env('APP_ENV')!=='production','name'=>'Rendora','public_demo'=>defined('RENDORA_PUBLIC_DEMO'),'bank_transfers'=>BankTransfers::enabled(),'paypal_sandbox'=>PayPalSandbox::enabled()]));
        $loginResponse=static function(Response $response,array $session,bool $mobile)use($json):Response{$cookie=Auth::cookieName().'='.$session['token'].'; Path=/; HttpOnly; SameSite=Strict; Max-Age=86400'.(env('COOKIE_SECURE','true')==='true'?'; Secure':'');if(!$mobile)unset($session['token']);return $json($response,$session)->withAddedHeader('Set-Cookie',$cookie);};
        $app->post('/api/v1/auth/login',fn(Request $r,Response $res)=>$loginResponse($res,$auth->login($body($r),$r->getServerParams()['REMOTE_ADDR']??'unknown'),($body($r)['client']??'')==='mobile'));
        $app->post('/api/v1/auth/register',fn(Request $r,Response $res)=>$loginResponse($res,$auth->register($body($r),$r->getServerParams()['REMOTE_ADDR']??'unknown'),false));
        $app->post('/api/v1/auth/demo',function(Request $r,Response $res)use($db,$auth,$body,$loginResponse){if(env('DEMO_MODE')!=='true'||env('APP_ENV')==='production')throw new HttpError(404,'Não encontrado.');$email=Validate::email($body($r));if(!in_array($email,['gestor@demo.homealone.pt','morador@demo.homealone.pt','proprietario@demo.homealone.pt','tecnico@demo.homealone.pt','gestor@demo.porto.pt','financeiro@demo.homealone.pt'],true))throw new HttpError(422,'Conta de demonstração inválida.');$u=$db->one('SELECT * FROM users WHERE email=?',[$email]);if(!$u||(defined('RENDORA_PUBLIC_DEMO')&&$u['platform_admin']))throw new HttpError(404,'Carregue os dados de demonstração.');if(defined('RENDORA_PUBLIC_DEMO')){
            $auth->throttle('demo:'.($r->getServerParams()['REMOTE_ADDR']??'unknown'),60,900);
            $db->run('DELETE FROM sessions WHERE expires_at<=CURRENT_TIMESTAMP');
            $db->run('DELETE FROM rate_limits WHERE expires_at<=?',[time()]);
        }return $loginResponse($res,$auth->session($u),false);});
        $app->get('/api/v1/auth/me',function(Request $r,Response $res)use($auth,$db,$json){$u=$auth->user($r);return $json($res,['user'=>['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'platform_admin'=>(bool)$u['platform_admin']],'csrf'=>$u['csrf'],'companies'=>$db->all('SELECT c.id,c.name,c.slug,m.role FROM companies c JOIN memberships m ON m.company_id=c.id WHERE m.user_id=? AND m.active=1',[$u['id']])]);});
        $app->post('/api/v1/auth/logout',function(Request $r,Response $res)use($auth,$db,$json){$u=$auth->user($r);$db->run('DELETE FROM sessions WHERE id=?',[$u['session_id']]);return $json($res,['ok'=>true])->withAddedHeader('Set-Cookie',Auth::cookieName().'=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0'.(env('COOKIE_SECURE','true')==='true'?'; Secure':''));});
        $app->post('/api/v1/auth/forgot',function(Request $r,Response $res)use($auth,$body,$json){$auth->resetRequest($body($r),$r->getServerParams()['REMOTE_ADDR']??'unknown',Language::english($r->getHeaderLine('X-Rendora-Language')));return $json($res,['message'=>'Se existir uma conta, receberá uma ligação por email.']);});
        $app->post('/api/v1/auth/reset',function(Request $r,Response $res)use($auth,$body,$json){$auth->reset($body($r));return $json($res,['ok'=>true]);});
        $route('GET','/dashboard',fn($s,$d,$a,$r)=>$finance->dashboard($s,$r->getQueryParams()));
        $route('GET','/properties',fn($s)=>$rentals->properties($s));
        $route('POST','/properties',fn($s,$d)=>$rentals->saveProperty($s,$d));
        $route('PATCH','/properties/{id}',fn($s,$d,$a)=>$rentals->saveProperty($s,$d,$a['id']));
        $route('POST','/properties/{id}/photos',function($s,$d,$a,$r)use($docs){$file=$r->getUploadedFiles()['file']??null;if(!$file)throw new HttpError(422,'Selecione uma fotografia.');return $docs->photo($s,$a['id'],$file);});
        $route('GET','/contacts',fn($s)=>$rentals->contacts($s));
        $route('POST','/contacts',fn($s,$d)=>$rentals->saveContact($s,$d));
        $route('PATCH','/contacts/{id}',fn($s,$d,$a)=>$rentals->saveContact($s,$d,$a['id']));
        $route('GET','/contracts',fn($s)=>$rentals->contracts($s));
        $route('POST','/contracts',fn($s,$d)=>$rentals->createContract($s,$d));
        $route('GET','/contracts/{id}',fn($s,$d,$a)=>$rentals->contractDetail($s,$a['id']));
        $route('PATCH','/contracts/{id}',fn($s,$d,$a)=>$rentals->changeContract($s,$a['id'],$d));
        $route('POST','/contracts/{id}/deposits',fn($s,$d,$a)=>$rentals->deposit($s,$a['id'],$d));
        $route('POST','/contracts/{id}/inspections',fn($s,$d,$a)=>$rentals->inspection($s,$a['id'],$d));
        $contractDocument=static function(Scope $s,string $id,bool $english)use($db,$rentals):array{
            $contract=$rentals->contractDetail($s,$id);
            $company=$db->one('SELECT name FROM companies WHERE id=?',[$s->companyId])['name'];
            $property=$s->row('properties',$contract['property_id']);
            return [$contract,$company,Pdf::contractText($contract,$company,$property,$english)];
        };
        $pdfResponse=static function(string $bytes,string $filename,bool $english):Response{
            $response=new \Slim\Psr7\Response();
            $response->getBody()->write($bytes);
            return $response->withHeader('Content-Type','application/pdf')
                ->withHeader('Content-Language',$english?'en':'pt-PT')
                ->withHeader('Content-Disposition','attachment; filename="'.$filename.'"');
        };
        $route('GET','/contracts/{id}/document',function($s,$d,$a,$r)use($contractDocument){
            return ['text'=>$contractDocument($s,$a['id'],Language::english($r->getHeaderLine('X-Rendora-Language')))[2]];
        });
        $route('GET','/contracts/{id}/pdf',function($s,$d,$a,$r)use($contractDocument,$pdfResponse){
            $english=Language::english($r->getHeaderLine('X-Rendora-Language'));
            [$contract,$company,$text]=$contractDocument($s,$a['id'],$english);
            return $pdfResponse(Pdf::contract($contract,$company,$text,$english),$english?'rental-agreement.pdf':'contrato.pdf',$english);
        });
        $route('GET','/charges/{id}/receipt.pdf',function($s,$d,$a,$r)use($db,$finance,$pdfResponse){
            $english=Language::english($r->getHeaderLine('X-Rendora-Language'));
            $charge=$finance->ledger($s,$a['id']);
            $company=$db->one('SELECT name FROM companies WHERE id=?',[$s->companyId])['name'];
            return $pdfResponse(Pdf::receipt($charge,$company,$english),$english?'payment-statement.pdf':'comprovativo-nao-fiscal.pdf',$english);
        });
        $route('GET','/charges',fn($s,$d,$a,$r)=>$finance->charges($s,$r->getQueryParams()));
        $route('POST','/charges/generate',fn($s,$d)=>$finance->generate($s,Validate::text($d,'period',7,7)));
        $route('GET','/charges/{id}',fn($s,$d,$a)=>$finance->ledger($s,$a['id']));
        $route('POST','/charges/{id}/confirm',fn($s,$d,$a)=>$finance->confirm($s,$a['id']));
        $route('POST','/charges/{id}/payments',fn($s,$d,$a)=>$finance->manual($s,$a['id'],$d));
        $route('POST','/charges/{id}/checkout',fn($s,$d,$a)=>$payments->checkout($s,$a['id']));
        $route('POST','/payments/{id}/refund',fn($s,$d,$a)=>$payments->refund($s,$a['id'],$d));
        $route('GET','/charges/{id}/receipt',function($s,$d,$a)use($finance){$c=$finance->ledger($s,$a['id']);return ['title'=>'Comprovativo de pagamentos — documento não fiscal','period'=>$c['period'],'amount_cents'=>$c['paid_cents'],'movements'=>$c['movements']];});
        $route('GET','/expenses',fn($s)=>$finance->expenses($s));
        $route('POST','/expenses',fn($s,$d)=>$finance->expense($s,$d));
        $route('GET','/reports/export',function($s,$d,$a,$r)use($finance){$s->requireRoles('ADMIN','MANAGER','FINANCE','OWNER');$rows=$finance->charges($s,$r->getQueryParams());$stream=fopen('php://temp','r+');fwrite($stream,"\xEF\xBB\xBF");fputcsv($stream,['Imóvel','Mês','Vencimento','Valor EUR','Recebido EUR','Saldo EUR','Estado'],';', '"', '');foreach($rows as $row){$safe=preg_match('/^(?:[\t\r\n]|\s*[=+@\-])/',$row['property_name'])?"'".$row['property_name']:$row['property_name'];fputcsv($stream,[$safe,$row['period'],$row['due_date'],number_format($row['amount_cents']/100,2,',',''),number_format($row['paid_cents']/100,2,',',''),number_format($row['balance_cents']/100,2,',',''),$row['status']],';','"','');}rewind($stream);$res=new \Slim\Psr7\Response();$res->getBody()->write(stream_get_contents($stream));fclose($stream);return $res->withHeader('Content-Type','text/csv; charset=utf-8')->withHeader('Content-Disposition','attachment; filename="rendas.csv"');});
        $route('GET','/tickets',fn($s)=>$ops->tickets($s));
        $route('POST','/tickets',fn($s,$d)=>$ops->createTicket($s,$d));
        $route('GET','/tickets/{id}',fn($s,$d,$a)=>$ops->ticket($s,$a['id']));
        $route('PATCH','/tickets/{id}',fn($s,$d,$a)=>$ops->updateTicket($s,$a['id'],$d));
        $route('POST','/tickets/{id}/notes',fn($s,$d,$a)=>$ops->note($s,$a['id'],$d));
        $route('POST','/tickets/{id}/reopen',fn($s,$d,$a)=>$ops->reopen($s,$a['id']));
        $route('GET','/leads',fn($s)=>$ops->leads($s));
        $route('POST','/leads',fn($s,$d)=>$ops->createLead($s,$d));
        $route('PATCH','/leads/{id}',fn($s,$d,$a)=>$ops->updateLead($s,$a['id'],$d));
        $route('POST','/leads/{id}/convert',fn($s,$d,$a)=>$ops->convert($s,$a['id'],$d));
        $route('GET','/conversations',fn($s)=>$ops->conversations($s));
        $route('POST','/conversations',fn($s,$d)=>$ops->createConversation($s,$d));
        $route('GET','/conversations/{id}/messages',fn($s,$d,$a)=>$ops->messages($s,$a['id']));
        $route('POST','/conversations/{id}/messages',fn($s,$d,$a)=>$ops->send($s,$a['id'],$d));
        $route('POST','/conversations/{id}/read',fn($s,$d,$a)=>$ops->markRead($s,$a['id']));
        $route('GET','/notifications',fn($s)=>$db->all('SELECT * FROM notifications WHERE company_id=? AND user_id=? ORDER BY created_at DESC LIMIT 200',[$s->companyId,$s->user['id']]));
        $route('POST','/notifications/{id}/read',function($s,$d,$a)use($db){$db->run('UPDATE notifications SET is_read=1 WHERE company_id=? AND user_id=? AND id=?',[$s->companyId,$s->user['id'],$a['id']]);return ['ok'=>true];});
        $route('POST','/broadcasts',fn($s,$d)=>$ops->broadcast($s,$d));
        $route('GET','/settings',fn($s)=>$admin->settings($s));
        $route('PATCH','/settings',fn($s,$d)=>$admin->update($s,$d));
        $route('GET','/members',fn($s)=>$admin->members($s));
        $route('POST','/members',fn($s,$d,$a,$r)=>$admin->invite($s,$d,Language::english($r->getHeaderLine('X-Rendora-Language'))));
        $route('POST','/members/{id}/invite',function($s,$d,$a,$r)use($db,$auth){$s->requireRoles('ADMIN');$m=$db->one('SELECT u.email FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.company_id=? AND m.user_id=? AND m.invitation_pending=1',[$s->companyId,$a['id']]);if(!$m)throw new HttpError(404,'Convite pendente não encontrado.');$auth->resetRequest(['email'=>$m['email']],'resend:'.$s->companyId.':'.$a['id'],Language::english($r->getHeaderLine('X-Rendora-Language')));$s->audit('member.invitation_resent',$a['id']);return ['queued'=>true];});
        $route('PATCH','/members/{id}',fn($s,$d,$a)=>$admin->updateMember($s,$a['id'],$d));
        $route('POST','/settings/connect',fn($s)=>$payments->connect($s));
        $route('POST','/settings/billing',fn($s,$d)=>$payments->billing($s,$d));
        $route('GET','/audit',function($s)use($db){$s->requireRoles('ADMIN');return $db->all('SELECT a.*,u.name user_name FROM audit a JOIN users u ON u.id=a.user_id WHERE company_id=? ORDER BY created_at DESC LIMIT 200',[$s->companyId]);});
        $route('GET','/jobs',function($s)use($db){$s->requireRoles('ADMIN');return $db->all('SELECT id,type,status,attempts,available_at,last_error FROM jobs WHERE company_id=? ORDER BY created_at DESC LIMIT 100',[$s->companyId]);});
        $route('POST','/jobs/{id}/retry',function($s,$d,$a)use($db){$s->requireRoles('ADMIN');$s->row('jobs',$a['id']);$db->run("UPDATE jobs SET status='PENDING',attempts=0,available_at=CURRENT_TIMESTAMP WHERE id=? AND company_id=? AND status='FAILED'",[$a['id'],$s->companyId]);return ['ok'=>true];});
        $proofFile=static function(Request $request): \Psr\Http\Message\UploadedFileInterface {
            $file=$request->getUploadedFiles()['file']??null;
            if(!$file instanceof \Psr\Http\Message\UploadedFileInterface)throw new HttpError(422,'Selecione um comprovativo.');
            return $file;
        };
        $proofDownload=static function(array $file): Response {
            [$path,$mime,$filename]=$file;
            return (new \Slim\Psr7\Response())->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStreamFromFile($path))
                ->withHeader('Content-Type',$mime)->withHeader('Content-Disposition','attachment; filename="'.$filename.'"');
        };
        $route('GET','/contracts/{id}/bank-account',fn($s,$d,$a)=>$bank->rentAccount($s,$a['id']));
        $route('PUT','/contracts/{id}/bank-account',fn($s,$d,$a)=>$bank->setRentAccount($s,$a['id'],$d));
        $route('GET','/charges/{id}/bank-transfer',fn($s,$d,$a)=>$bank->rent($s,$a['id']));
        $route('POST','/charges/{id}/bank-transfer',fn($s,$d,$a,$r)=>$bank->upload($s,$a['id'],$d,$proofFile($r)));
        $route('GET','/settings/bank-transfer',fn($s)=>$bank->subscription($s));
        $route('POST','/settings/bank-transfer',fn($s,$d,$a,$r)=>$bank->upload($s,null,$d,$proofFile($r)));
        $route('GET','/bank-proofs/{id}',fn($s,$d,$a)=>$proofDownload($bank->download($s,$s->user,$a['id'])));
        $route('POST','/bank-proofs/{id}/review',fn($s,$d,$a)=>$bank->review($s,$s->user,$a['id'],$d));
        $app->get('/api/v1/platform/bank-proofs',fn(Request $r,Response $res)=>$json($res,$bank->platformProofs($auth->user($r))));
        $app->get('/api/v1/platform/bank-proofs/{id}',fn(Request $r,Response $res,array $a)=>$proofDownload($bank->download(null,$auth->user($r),$a['id'])));
        $app->post('/api/v1/platform/bank-proofs/{id}/review',fn(Request $r,Response $res,array $a)=>$json($res,$bank->review(null,$auth->user($r),$a['id'],$body($r))));
        $route('GET','/documents',fn($s,$d,$a,$r)=>$docs->list($s,$r->getQueryParams()['entity_type']??'',$r->getQueryParams()['entity_id']??''));
        $route('POST','/documents',function($s,$d,$a,$r)use($docs){$file=$r->getUploadedFiles()['file']??null;if(!$file)throw new HttpError(422,'Selecione um ficheiro.');return $docs->upload($s,Validate::text($d,'entity_type'),Validate::text($d,'entity_id'),$file);});
        $route('GET','/documents/{id}/download',function($s,$d,$a)use($docs){$doc=$docs->download($s,$a['id']);$res=new \Slim\Psr7\Response();$res->getBody()->write(file_get_contents($doc['path']));return $res->withHeader('Content-Type',$doc['mime_type'])->withHeader('Cache-Control','no-store')->withHeader('Content-Disposition',"attachment; filename*=UTF-8''".rawurlencode($doc['name']));});
        $route('POST','/devices',function($s,$d)use($db){
            $token=Validate::text($d,'token',10,2000);
            $platform=Validate::choice($d,'platform',['ios','android','web']);
            $db->transaction(function()use($db,$s,$token,$platform){
                $device=$db->one('SELECT id FROM devices WHERE company_id=? AND user_id=? AND token=?',[$s->companyId,$s->user['id'],$token]);
                if($device)$db->run('UPDATE devices SET platform=? WHERE id=?',[$platform,$device['id']]);
                else $db->insert('devices',['company_id'=>$s->companyId,'user_id'=>$s->user['id'],'token'=>$token,'platform'=>$platform]);
            });
            return ['registered'=>true,'push_enabled'=>false];
        });
        $route('GET','/charges/{id}/paypal-sandbox',fn($s,$d,$a)=>$paypal->rent($s,$a['id']));
        $route('POST','/charges/{id}/paypal-sandbox',fn($s,$d,$a)=>$paypal->startRent($s,$a['id']));
        $route('POST','/charges/{id}/paypal-sandbox/reconcile',fn($s,$d,$a)=>$paypal->reconcileRent($s,$a['id']));
        $route('GET','/settings/paypal-sandbox',fn($s)=>$paypal->subscription($s));
        $route('POST','/settings/paypal-sandbox',fn($s,$d)=>$paypal->startSubscription($s,$d));
        $app->post('/api/v1/webhooks/paypal-sandbox/{app:[a-z0-9_-]{1,50}}',function(Request $r,Response $res,array $a)use($paypal,$body,$json){
            $headers=[];
            foreach(['auth_algo','cert_url','transmission_id','transmission_sig','transmission_time'] as $name){
                $headers[$name]=$r->getHeaderLine('PayPal-'.str_replace('_','-',$name));
            }
            return $json($res,$paypal->webhook($a['app'],$body($r),$headers,(string)$r->getBody()));
        });
        $app->post('/api/v1/webhooks/stripe',fn(Request $r,Response $res)=>$json($res,$payments->webhook((string)$r->getBody(),$r->getHeaderLine('Stripe-Signature'))));
        $app->post('/api/v1/webhooks/billing',fn(Request $r,Response $res)=>$json($res,$payments->webhook((string)$r->getBody(),$r->getHeaderLine('Stripe-Signature'),true)));
        $app->get('/api/v1/public/{slug}',fn(Request $r,Response $res,array $a)=>$json($res,$admin->publicCompany($a['slug'])));
        $app->get('/api/v1/public/photos/{id}',function(Request $r,Response $res,array $a)use($db,$auth){$doc=$db->one("SELECT d.*,p.published,p.photos FROM documents d JOIN properties p ON p.id=d.entity_id AND p.company_id=d.company_id WHERE d.id=? AND d.entity_type='property'",[$a['id']]);if(!$doc||!str_starts_with($doc['mime_type'],'image/')||!in_array('/api/v1/public/photos/'.$a['id'],json_decode($doc['photos'],true),true))throw new HttpError(404,'Fotografia não encontrada.');if(!$doc['published']){$user=$auth->user($r);$m=$db->one('SELECT role FROM memberships WHERE company_id=? AND user_id=? AND active=1',[$doc['company_id'],$user['id']]);if(!$m)throw new HttpError(404,'Fotografia não encontrada.');(new Scope($db,$doc['company_id'],$user,$m['role']))->property($doc['entity_id']);}$path=dirname(__DIR__).'/storage/uploads/'.$doc['storage_path'];if(!is_file($path))throw new HttpError(404,'Fotografia não encontrada.');$res->getBody()->write(file_get_contents($path));return $res->withHeader('Content-Type',$doc['mime_type'])->withHeader('Cache-Control','no-store');});
        $app->post('/api/v1/public/{slug}/leads',fn(Request $r,Response $res,array $a)=>$json($res,$admin->publicLead($a['slug'],$body($r),$r->getServerParams()['REMOTE_ADDR']??'unknown'),201));
        $app->get('/api/v1/platform',fn(Request $r,Response $res)=>$json($res,$admin->platform($auth->user($r))));
        $app->post('/api/v1/platform/companies',fn(Request $r,Response $res)=>$json($res,$admin->createCompany($auth->user($r),$body($r),Language::english($r->getHeaderLine('X-Rendora-Language'))),201));
        $app->post('/api/v1/platform/companies/{id}/administrators/{userId}/invite',fn(Request $r,Response $res,array $a)=>$json($res,$admin->resendCompanyInvite($auth->user($r),$a['id'],$a['userId'],Language::english($r->getHeaderLine('X-Rendora-Language')))));
        $app->post('/api/v1/platform/plans',function(Request $r,Response $res)use($auth,$db,$json,$body){$u=$auth->user($r);if(!$u['platform_admin'])throw new HttpError(403,'Acesso reservado.');$d=$body($r);return $json($res,$db->insert('plans',['name'=>Validate::text($d,'name',2,100),'stripe_price_id'=>!empty($d['stripe_price_id'])?Validate::text($d,'stripe_price_id',6,100):null,'property_limit'=>Validate::integer($d,'property_limit',1),'member_limit'=>Validate::integer($d,'member_limit',1)]));});
        $app->get('/sitemap.xml',fn(Request $r,Response $res)=>PublicPages::sitemap($res,$db));
        $app->get('/robots.txt',fn(Request $r,Response $res)=>PublicPages::robots($res));
        foreach(LegalPages::routes() as $page){
            $app->get('/'.$page[0],fn(Request $r,Response $res)=>LegalPages::render($res,$page,false));
            $app->get('/'.$page[1],fn(Request $r,Response $res)=>LegalPages::render($res,$page,true));
        }
        $app->get('/sobre',fn(Request $r,Response $res)=>PublicPages::about($res,false));
        $app->get('/en/about',fn(Request $r,Response $res)=>PublicPages::about($res,true));
        foreach([PublicPages::FEATURES_PT=>false,PublicPages::FEATURES_EN=>true] as $path=>$english){
            $app->get($path,fn(Request $r,Response $res)=>PublicPages::about($res,$english,true,
                ($r->getQueryParams()['pedido']??'')==='recebido'?'received':''));
            $app->post($path,function(Request $r,Response $res)use($db,$auth,$body,$path,$english){
                try {
                    (new DemoEnquiries($db,$auth))->submit($body($r),$r->getServerParams()['REMOTE_ADDR']??'unknown');
                } catch(HttpError $error) {
                    if(!in_array($error->status,[422,429],true))throw $error;
                    return PublicPages::about($res->withStatus($error->status),$english,true,$error->status===429?'limited':'invalid');
                }
                return $res->withHeader('Location',$path.'?pedido=recebido#demonstracao')->withStatus(303);
            });
        }
        $app->get('/alojamentos/{slug}',fn(Request $r,Response $res,array $a)=>PublicPages::catalog($res,self::shell(),$admin->publicCompany($a['slug']),$a['slug'],false));
        $app->get('/en/properties/{slug}',fn(Request $r,Response $res,array $a)=>PublicPages::catalog($res,self::shell(),$admin->publicCompany($a['slug']),$a['slug'],true));
        $app->get('/',fn(Request $r,Response $res)=>PublicPages::login($res,self::shell(),false));
        $app->get('/en/',fn(Request $r,Response $res)=>PublicPages::login($res,self::shell(),true));
        $app->get('/en',function(Request $r,Response $res){
            $query=$r->getUri()->getQuery();
            return $res->withHeader('Location','/en/'.($query!==''?'?'.$query:''))->withStatus(308);
        });
        if(defined('RENDORA_PUBLIC_DEMO')){
            $app->add(function(Request $r,$handler){
                $pattern=\Slim\Routing\RouteContext::fromRequest($r)->getRoute()?->getPattern();
                $method=$r->getMethod();
                $allowed=in_array($method,['GET','HEAD'],true)
                    ? !str_starts_with($pattern??'','/api/v1/platform')
                    : $method==='POST'&&in_array($pattern,['/api/v1/auth/demo','/api/v1/auth/logout'],true);
                if(!$allowed)throw new HttpError(403,'Ação indisponível na demonstração pública.');
                return $handler->handle($r);
            });
        }
        $app->addBodyParsingMiddleware();$app->addRoutingMiddleware();
        $app->add(function(Request $r,$handler)use($json){try{$origin=$r->getHeaderLine('Origin');$allowed=[rtrim(env('APP_URL'),'/')];if(env('APP_ENV')!=='production'&&!defined('RENDORA_PUBLIC_DEMO'))$allowed=[...$allowed,'http://127.0.0.1:5173','http://localhost:5173'];if(!in_array($r->getMethod(),['GET','HEAD','OPTIONS'])&&$origin&&!in_array($origin,$allowed,true))throw new HttpError(403,'Origem do pedido não autorizada.');if((int)$r->getHeaderLine('Content-Length')>12*1024*1024)throw new HttpError(413,'Pedido demasiado grande.');$res=$handler->handle($r);}catch(HttpError $e){$res=$json(new \Slim\Psr7\Response(),['message'=>$e->getMessage()],$e->status);}catch(\Slim\Exception\HttpNotFoundException){$res=$json(new \Slim\Psr7\Response(),['message'=>'Não encontrado.'],404);}catch(\Throwable $e){$id=Database::id();error_log('HomeAlone error '.$id.' '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());$res=$json(new \Slim\Psr7\Response(),['message'=>'Não foi possível concluir a operação.','request_id'=>$id],500);}return $res->withHeader('X-Content-Type-Options','nosniff')->withHeader('X-Frame-Options','DENY')->withHeader('Referrer-Policy','same-origin')->withHeader('Cache-Control','no-store');});
        if(defined('RENDORA_PUBLIC_DEMO')){
            $app->add(fn(Request $r,$handler)=>$handler->handle($r)->withHeader('X-Robots-Tag','noindex'));
        }
        return $app;
    }
    private static function shell(): string { $path=dirname(__DIR__).'/public/app.html';if(!is_file($path))throw new HttpError(503,'Execute npm run build para preparar a interface.');return file_get_contents($path); }
}
