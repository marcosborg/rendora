import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdir, readFile, rm } from 'node:fs/promises';
import path from 'node:path';
const root=process.cwd();const db=path.join(root,'.local','http-tests',`test-${Date.now()}.sqlite`);const port=18082;const base=`http://127.0.0.1:${port}`;let server,admin,resident,porto,tech,createdProperty,contract,charge,doc;let stderr='';
async function req(url,options={},session=admin,company='company-lisbon'){const response=await fetch(base+'/api/v1'+url,{...options,headers:{...(options.body instanceof FormData?{}:{'Content-Type':'application/json'}),...(session?{'Cookie':session.cookie,'X-CSRF-Token':session.csrf,'X-Company-Id':company}:{}),...options.headers}});const content=await response.text();let data;try{data=JSON.parse(content);}catch{data=content;}return {response,data,status:response.status};}
const post=(url,data,session=admin,company)=>req(url,{method:'POST',body:JSON.stringify(data)},session,company);
async function login(email){const {response,data,status}=await post('/auth/demo',{email},null);assert.equal(status,200,JSON.stringify(data));return {cookie:response.headers.get('set-cookie').split(';')[0],csrf:data.csrf};}
before(async()=>{await mkdir(path.dirname(db),{recursive:true});const env={...process.env,DB_PATH:db,APP_ENV:'test',DEMO_MODE:'true',COOKIE_SECURE:'false',APP_URL:base,STRIPE_SECRET_KEY:'',RESEND_API_KEY:'',BANK_TRANSFERS_ENABLED:'true',ALLOW_LIVE_PAYMENTS:'false',SUBSCRIPTION_BANK_BENEFICIARY:'Netlook Teste',SUBSCRIPTION_BANK_IBAN:'PT50000000000000000000001',BANK_PLAN_ESSENTIAL_ID:'plan-demo',BANK_PLAN_ESSENTIAL_MONTH_CENTS:'1900'};const mysql=process.env.RENDORA_TRIAL_MYSQL_DSN;
if(mysql){
  assert.match(mysql,/^mysql:unix_socket=[^;]+;dbname=rendora_trial(?:;charset=utf8mb4)?$/);
  Object.assign(env,{DB_MYSQL_DSN:mysql,DB_MYSQL_USER:process.env.RENDORA_TRIAL_MYSQL_USER||'',DB_MYSQL_PASSWORD:process.env.RENDORA_TRIAL_MYSQL_PASSWORD||''});
}else{
  env.DB_MYSQL_DSN='';
  execFileSync('php',['bin/console.php','migrate'],{cwd:root,env});
  execFileSync('php',['bin/console.php','seed'],{cwd:root,env});
}server=spawn('php',['-S',`127.0.0.1:${port}`,'-t','public','public/router.php'],{cwd:root,env,stdio:['ignore','ignore','pipe']});server.stderr.on('data',chunk=>{stderr+=chunk.toString();});for(let i=0;i<50;i++){try{if((await fetch(base+'/api/v1/health')).ok)break;}catch{}await new Promise(r=>setTimeout(r,50));}admin=await login('gestor@demo.homealone.pt');resident=await login('morador@demo.homealone.pt');porto=await login('gestor@demo.porto.pt');tech=await login('tecnico@demo.homealone.pt');});
after(()=>{server?.kill();});
test('public server renders catalog HTML for SEO',async()=>{const response=await fetch(base+'/alojamentos/casa-lisboa');assert.equal(response.status,200);const html=await response.text();assert.match(html,/<title>Casa Lisboa/);assert.match(html,/Alcântara/);assert.doesNotMatch(html,/stripe_account_id/);});
test('bilingual public pages have reciprocal URLs and login stays out of the index', async () => {
  const origin = base;
  const pairs = [
    ['/sobre', '/en/about'],
    ['/software-gestao-arrendamentos', '/en/rental-management-software'],
  ];
  for (const [pt, en] of pairs) {
    for (const [route, language] of [[pt, 'pt-PT'], [en, 'en']]) {
      const response = await fetch(origin + route);
      assert.equal(response.status, 200);
      assert.equal(response.headers.get('x-robots-tag'), null);
      const html = await response.text();
      assert.ok(html.includes(`<html lang="${language}">`));
      assert.ok(html.includes(`rel="canonical" href="${origin}${route}"`));
      assert.ok(html.includes(`rel="alternate" hreflang="pt-PT" href="${origin}${pt}"`));
      assert.ok(html.includes(`rel="alternate" hreflang="en" href="${origin}${en}"`));
      assert.ok(html.includes(`rel="alternate" hreflang="x-default" href="${origin}${pt}"`));
      const related = pairs.find(pair => pair[0] !== pt);
      assert.ok(html.includes(`<a href="${related[language === 'en' ? 1 : 0]}">`));
    }
  }
  const englishLogin = await fetch(origin + '/en/');
  assert.equal(englishLogin.headers.get('x-robots-tag'), 'noindex');
  assert.match(await englishLogin.text(), /<html lang="en">/);
  const catalog = await fetch(origin + '/en/properties/casa-lisboa');
  assert.equal(catalog.status, 200);
  assert.equal(catalog.headers.get('x-robots-tag'), 'noindex');
  assert.match(await catalog.text(), /Casa Lisboa.*Rental properties/);
  const sitemap = await (await fetch(origin + '/sitemap.xml')).text();
  for (const [pt, en] of pairs) {
    for (const route of [pt, en]) {
      const entry = sitemap.split('</url>').find(xml => xml.includes(`<loc>${origin}${route}</loc>`));
      assert.ok(entry);
      assert.ok(entry.includes(`hreflang="pt-PT" href="${origin}${pt}"`));
      assert.ok(entry.includes(`hreflang="en" href="${origin}${en}"`));
    }
  }
  assert.doesNotMatch(sitemap, /\/en\/properties\//);
});
test('commercial pages show indicative plans and queue a single demo request', async () => {
  const pt = '/software-gestao-arrendamentos';
  const en = '/en/rental-management-software';
  for (const [path, label, demo] of [[pt, 'Pedir uma demonstração', '/'], [en, 'Request a demo', '/en/']]) {
    const html = await (await fetch(base + path)).text();
    assert.ok(html.includes(label));
    for (const price of ['19 €', '39 €', '190 €', '390 €']) assert.ok(html.includes(price));
    assert.ok(html.includes(`href="https://demo.rendora.pt${demo}"`));
    assert.ok(html.includes(`action="${path}#demonstracao" method="post"`));
  }
  const send = (path, data, origin = base) => fetch(base + path, {
    method: 'POST', body: new URLSearchParams(data), headers: { Origin: origin }, redirect: 'manual',
  });
  const data = { name: 'Demo Tester', email: 'enquiry@example.test', company: 'Test Company', units: '11-40' };
  assert.equal((await send(pt, { ...data, units: 'invalid' })).status, 422);
  assert.equal((await send(pt, data, 'https://unrelated.example')).status, 403);
  assert.equal((await send(pt, { ...data, email: 'bot@example.test', website: 'bot.example' })).status, 303);
  for (const path of [pt, en]) {
    const response = await send(path, data);
    assert.equal(response.status, 303);
    const confirmation = await fetch(base + response.headers.get('location'));
    assert.match(await confirmation.text(), /Pedido recebido|Request received/);
  }
  const jobs = JSON.parse(execFileSync('php', ['-r', 'require "src/bootstrap.php"; echo json_encode(database()->all("SELECT payload FROM jobs WHERE dedupe_key LIKE ?", ["demo-enquiry:%"]));'], {
    cwd: root, env: { ...process.env, DB_PATH: db, DB_MYSQL_DSN: '' }, encoding: 'utf8',
  }));
  assert.equal(jobs.length, 1);
  const payload = JSON.parse(jobs[0].payload);
  assert.equal(payload.email, 'info@rendora.pt');
  assert.match(payload.body, /enquiry@example.test/);
});
test('legal drafts have localised links and stay out of the index', async () => {
  const pairs = [
    ['/legal', '/en/legal'],
    ['/privacidade', '/en/privacy'],
    ['/cookies', '/en/cookies'],
    ['/termos', '/en/terms'],
  ];
  for (const [pt, en] of pairs) {
    for (const [route, language, alternate] of [[pt, 'pt-PT', en], [en, 'en', pt]]) {
      const response = await fetch(base + route);
      assert.equal(response.status, 200);
      assert.equal(response.headers.get('x-robots-tag'), 'noindex');
      const html = await response.text();
      assert.ok(html.includes(`<html lang="${language}">`));
      assert.ok(html.includes(`href="${alternate}"`));
      assert.ok(html.includes('mailto:info@rendora.pt'));
      assert.match(html, /Minuta para revisão|Draft for review/);
    }
  }
  for (const [route, column] of [['/sobre', 0], ['/en/about', 1], ['/alojamentos/casa-lisboa', 0], ['/en/properties/casa-lisboa', 1]]) {
    const html = await (await fetch(base + route)).text();
    for (const pair of pairs) assert.ok(html.includes(`href="${pair[column]}"`));
  }
  const sitemap = await (await fetch(base + '/sitemap.xml')).text();
  for (const pair of pairs) for (const route of pair) assert.ok(!sitemap.includes(`${base}${route}</loc>`));
});
test('bank transfers keep rent and subscription proofs private and only credit verified receipts',async t=>{
  const iban='PT50000000000000000000001';
  const owner=await login('proprietario@demo.homealone.pt');
  const future=new Date();
  future.setUTCDate(1);
  future.setUTCMonth(future.getUTCMonth()+2);
  const period=future.toISOString().slice(0,7);
  assert.equal((await post('/charges/generate',{period})).status,200);
  const charges=(await req(`/charges?period=${period}`,{},resident)).data;
  const charge=charges.find(c=>c.confirmed&&c.balance_cents>0);
  assert.ok(charge);
  const contract=charge.contract_id;
  const members=(await req('/members')).data;
  const ownerId=members.find(m=>m.email==='proprietario@demo.homealone.pt').id;
  const account={recipient_id:ownerId,beneficiary:'Beneficiário fictício',iban};
  const accountPath=`/contracts/${contract}/bank-account`;
  assert.equal((await req(accountPath,{},resident)).status,403);
  assert.equal((await req(accountPath,{method:'PUT',body:JSON.stringify({...account,iban:iban.slice(0,-1)+'2'})})).status,422);
  assert.equal((await req(accountPath,{method:'PUT',body:JSON.stringify(account)})).status,200);
  assert.equal((await req(accountPath,{},porto,'company-porto')).status,404);
  const rentPath=`/charges/${charge.id}/bank-transfer`;
  const rent=(await req(rentPath,{},resident)).data;
  assert.equal(rent.instructions.beneficiary,account.beneficiary);
  assert.equal(rent.instructions.iban,iban);
  const upload=async(path,fields,session,content='%PDF-1.4\n%%EOF')=>{
    const form=new FormData();
    Object.entries(fields).forEach(([key,value])=>form.set(key,value));
    form.set('file',new Blob([content],{type:'application/pdf'}),'test.pdf');
    return req(path,{method:'POST',body:form},session);
  };
  const remember=id=>t.after(()=>rm(path.join(root,'storage','bank-proofs',id),{force:true}));
  assert.equal((await upload(rentPath,{version:'0'.repeat(64)},resident)).status,409);
  assert.equal((await upload(rentPath,{version:rent.instructions.version},resident,'not a PDF')).status,422);
  const submitted=await upload(rentPath,{version:rent.instructions.version},resident);
  assert.equal(submitted.status,200,JSON.stringify(submitted.data));
  const proof=submitted.data;
  remember(proof.id);
  assert.equal(proof.status,'PENDING');
  assert.equal((await upload(rentPath,{version:rent.instructions.version},resident)).data.id,proof.id);
  assert.equal((await req(`/charges/${charge.id}`,{},resident)).data.balance_cents,charge.balance_cents);
  const proofPath=`/bank-proofs/${proof.id}`;
  assert.equal((await req(proofPath,{},admin)).status,403);
  assert.equal((await req(proofPath,{},porto,'company-porto')).status,404);
  assert.equal((await req(proofPath,{},owner)).response.headers.get('content-type'),'application/pdf');
  const review={status:'APPROVED',bank_reference:'FIXTURE-RENT-1'};
  assert.equal((await post(proofPath+'/review',review,resident)).status,403);
  assert.equal((await post(proofPath+'/review',review,owner)).status,200);
  assert.equal((await post(proofPath+'/review',review,owner)).status,200);
  const settled=(await req(`/charges/${charge.id}`,{},resident)).data;
  assert.equal(settled.balance_cents,0);
  assert.equal(settled.movements.filter(m=>m.external_id===`bank:${proof.id}`).length,1);

  const subPath='/settings/bank-transfer';
  assert.equal((await req(subPath,{},resident)).status,403);
  const offer=(await req(subPath)).data.offers[0];
  assert.equal(offer.beneficiary,'Netlook Teste');
  assert.equal(offer.amount_cents,1900);
  const sub=await upload(subPath,{plan_id:offer.plan_id,months:'1',version:offer.version},admin,'%PDF-1.4\n% Subscription\n%%EOF');
  assert.equal(sub.status,200,JSON.stringify(sub.data));
  remember(sub.data.id);
  const subReview=`/platform/bank-proofs/${sub.data.id}/review`;
  assert.equal((await post(subReview,{status:'APPROVED',bank_reference:'FIXTURE-SUB-1'},porto)).status,403);
  assert.equal((await post(subReview,review)).status,409);
  const approved=await post(subReview,{status:'APPROVED',bank_reference:'FIXTURE-SUB-1'});
  assert.equal(approved.status,200,JSON.stringify(approved.data));
  assert.match(approved.data.paid_until,/^\d{4}-\d{2}-\d{2}$/);
  assert.equal((await req('/settings')).data.subscription_status,'bank_transfer');
});
test('anonymous access and cross-company access fail',async()=>{assert.equal((await req('/properties',{},null)).status,401);assert.equal((await req('/properties',{},resident,'company-porto')).status,403);const rows=await req('/properties',{},porto,'company-porto');assert.equal(rows.data.length,1);});
test('real JSON property creation, edit and reload persist',async()=>{let r=await post('/properties',{name:'HTTP test apartment',type:'APARTMENT',address:'Rua de Teste, 10',city:'Lisboa',rent_cents:110000,area:70,bedrooms:2,description:'Apartamento para testar a persistência.',parent_id:null,owner_id:'u-owner',published:false,photos:[],amenities:['Mobilado']});assert.equal(r.status,200,JSON.stringify(r.data));createdProperty=r.data.id;r=await req(`/properties/${createdProperty}`,{method:'PATCH',body:JSON.stringify({...r.data,name:'HTTP test updated',photos:[],amenities:['Mobilado']})});assert.equal(r.status,200,JSON.stringify(r.data));const list=(await req('/properties')).data;assert.equal(list.find(p=>p.id===createdProperty).name,'HTTP test updated');});
test('CSRF protection rejects cookie mutation with missing token',async()=>{const r=await req('/charges/generate',{method:'POST',headers:{'X-CSRF-Token':''},body:JSON.stringify({period:'2035-01'})});assert.equal(r.status,403);});
test('create contract, generate, partial payment and full settlement via HTTP',async()=>{let r=await post('/contracts',{property_id:createdProperty,contact_ids:['co-beatriz'],start_date:'2035-01-15',end_date:'2035-12-31',rent_cents:110000,charges_cents:0,deposit_cents:220000,due_day:31,notes:''});assert.equal(r.status,200,JSON.stringify(r.data));contract=r.data.id;r=await post('/charges/generate',{period:'2035-01'});assert.equal(r.status,200);const charges=(await req('/charges?period=2035-01')).data;charge=charges.find(c=>c.contract_id===contract);assert.equal(charge.confirmed,0);r=await post(`/charges/${charge.id}/payments`,{amount_cents:10000,kind:'PAYMENT',reason:'Transferência de teste',idempotency_key:'http-payment-1'});assert.equal(r.status,422);assert.equal((await post(`/charges/${charge.id}/confirm`,{})).status,200);r=await post(`/charges/${charge.id}/payments`,{amount_cents:10000,kind:'PAYMENT',reason:'Transferência de teste',idempotency_key:'http-payment-1'});assert.equal(r.status,200);r=await post(`/charges/${charge.id}/payments`,{amount_cents:charge.amount_cents-10000,kind:'PAYMENT',reason:'Restante transferência de teste',idempotency_key:'http-payment-2'});assert.equal(r.status,200);assert.equal((await req(`/charges/${charge.id}`)).data.status,'PAID');});
test('multipart file upload, authorized download and tenant isolation',async()=>{const form=new FormData();form.append('entity_type','contract');form.append('entity_id',contract);form.append('file',new Blob(['Documento privado de teste.'],{type:'text/plain'}),'contrato-teste.txt');let r=await req('/documents',{method:'POST',body:form});assert.equal(r.status,200,JSON.stringify(r.data));doc=r.data.id;r=await req(`/documents/${doc}/download`);assert.equal(r.status,200);assert.equal(r.data,'Documento privado de teste.');assert.equal((await req(`/documents/${doc}/download`,{},resident)).status,404);assert.equal((await req(`/documents/${doc}/download`,{},porto,'company-porto')).status,404);});
test('reject executable file uploads',async()=>{const form=new FormData();form.append('entity_type','contract');form.append('entity_id',contract);form.append('file',new Blob(['<?php echo "should not run";'],{type:'application/x-php'}),'malicious.php');assert.equal((await req('/documents',{method:'POST',body:form})).status,422);});
test('photo upload remains private until explicit publication',async()=>{const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aHhoAAAAASUVORK5CYII=','base64');const form=new FormData();form.append('file',new Blob([png],{type:'image/png'}),'foto.png');let r=await req(`/properties/${createdProperty}/photos`,{method:'POST',body:form});assert.equal(r.status,200,JSON.stringify(r.data));const url=r.data.url;assert.equal((await fetch(base+url)).status,401);assert.equal((await fetch(base+url,{headers:{Cookie:admin.cookie}})).status,200);const p=(await req('/properties')).data.find(p=>p.id===createdProperty);r=await req(`/properties/${createdProperty}`,{method:'PATCH',body:JSON.stringify({...p,published:true})});assert.equal(r.status,200,JSON.stringify(r.data));assert.equal((await fetch(base+url)).status,200);});
test('resident cannot inspect others notes and technician has assigned-only access',async()=>{const r=await req('/tickets/t-water',{},resident);assert.equal(r.status,200);assert.equal(r.data.notes.some(n=>n.internal===1),false);assert.equal((await req('/tickets/t-light',{},tech)).status,404);});
test('ticket created, assigned, resolved and reopened through REST',async()=>{let r=await post('/tickets',{property_id:'p-estrela',title:'Teste HTTP de manutenção',description:'Descrição completa do teste de manutenção.',category:'OTHER',priority:'NORMAL'},resident);assert.equal(r.status,200);const id=r.data.id;r=await req(`/tickets/${id}`,{method:'PATCH',body:JSON.stringify({assignee_id:'u-tech',status:'IN_PROGRESS',note:'Atribuído'})});assert.equal(r.status,200);r=await req(`/tickets/${id}`,{method:'PATCH',body:JSON.stringify({status:'RESOLVED',note:'Intervenção concluída.'})},tech);assert.equal(r.status,200);r=await post(`/tickets/${id}/reopen`,{},resident);assert.equal(r.status,200);assert.equal(r.data.status,'TRIAGE');});
test('public inquiry appears in CRM without exposing private contact IDs',async()=>{const r=await post('/public/casa-lisboa/leads',{property_id:'p-graca',name:'Visitante HTTP',email:'visitor@example.test',phone:'',notes:'Gostaria de visitar.',consent:true},null);assert.equal(r.status,201);assert.deepEqual(r.data,{received:true});assert.ok((await req('/leads')).data.some(l=>l.name==='Visitante HTTP'));});
test('CSV export is authorized and contains only selected company',async()=>{let r=await req('/reports/export?period=2035-01');assert.equal(r.status,200);assert.match(r.data,/HTTP test updated/);assert.doesNotMatch(r.data,/Cedofeita/);assert.equal((await req('/reports/export',{},resident)).status,403);});
test('integration errors are actionable without pretending to charge',async()=>{const ch=(await req('/charges')).data.find(c=>c.balance_cents>0);const r=await post(`/charges/${ch.id}/checkout`,{});assert.equal(r.status,503);assert.match(r.data.message,/Configure a Stripe/);});
test('logout revokes session',async()=>{const session=await login('financeiro@demo.homealone.pt');assert.equal((await post('/auth/logout',{},session)).status,200);assert.equal((await req('/auth/me',{},session)).status,401);});
test('PDF contracts and non-fiscal receipts are actual PDF downloads',async()=>{for(const url of [`/contracts/${contract}/pdf`,`/charges/${charge.id}/receipt.pdf`]){const r=await req(url);assert.equal(r.status,200);assert.match(r.response.headers.get('content-type'),/application\/pdf/);assert.ok(r.data.startsWith('%PDF-'));assert.ok(r.data.length>1000);}});
test('English downloads localise their labels and preserve PDF format',async()=>{
  for(const [url,filename,title] of [
    [`/contracts/${contract}/pdf`,'rental-agreement.pdf','Rental agreement'],
    [`/charges/${charge.id}/receipt.pdf`,'payment-statement.pdf','Payment statement'],
  ]){
    const r=await req(url,{headers:{'X-Rendora-Language':'en-GB'}});
    assert.equal(r.status,200);
    assert.equal(r.response.headers.get('content-language'),'en');
    assert.ok(r.response.headers.get('content-disposition').includes(filename));
    assert.ok(r.data.startsWith('%PDF-'));
    assert.ok(r.data.includes(`/Title (${title})`));
  }
  const r=await req('/contracts/ct-ines/document',{headers:{'X-Rendora-Language':'en-GB'}});
  assert.match(r.data.text,/^Rental agreement between/);
});
test('concurrent overlapping contract attempts serialize safely',async()=>{const worker=()=>new Promise((resolve,reject)=>{const child=spawn('php',['tests/contract-worker.php',db],{cwd:root,stdio:['ignore','pipe','pipe']});let out='',err='';child.stdout.on('data',b=>out+=b);child.stderr.on('data',b=>err+=b);child.on('exit',code=>code===0?resolve(Number(out)):reject(new Error(err)));});assert.deepEqual((await Promise.all([worker(),worker()])).sort(),[200,409]);});
test('HTTP server wrote only to the explicitly selected test database',()=>{const value=execFileSync('php',['-r',"$db=new PDO('sqlite:'.$argv[1]);$q=$db->prepare('SELECT name FROM properties WHERE id=?');$q->execute([$argv[2]]);echo $q->fetchColumn();",db,createdProperty],{cwd:root,encoding:'utf8'});assert.equal(value,'HTTP test updated');});
test('platform company creation requires global admin and valid CSRF',async()=>{
 const payload={name:'Nova empresa HTTP',slug:'nova-empresa-http',email:'equipa@example.test',admin_name:'Novo administrador',admin_email:'novo-admin@example.test'};
 assert.equal((await post('/platform/companies',payload,null)).status,401);
 assert.equal((await post('/platform/companies',payload,porto)).status,403);
 assert.equal((await post('/platform/companies',payload,resident)).status,403);
 assert.equal((await req('/platform/companies',{method:'POST',body:JSON.stringify(payload),headers:{'X-CSRF-Token':''}})).status,403);
 const r=await post('/platform/companies',payload,admin,'');assert.equal(r.status,201,JSON.stringify(r.data));
 assert.equal(r.data.administrator.invitation_pending,1);assert.equal(r.data.administrator.active,0);
 assert.equal((await post('/platform/companies',payload)).status,409);
 const list=(await req('/platform')).data.companies;const c=list.find(c=>c.id===r.data.id);
 assert.equal(c.administrators[0].email,payload.admin_email);assert.equal(c.administrators[0].invitation_pending,1);
 assert.equal((await req('/properties',{},admin,c.id)).status,403);
 const catalog=(await req('/public/'+payload.slug,{},null)).data;assert.deepEqual(catalog.properties,[]);
 assert.equal((await post(`/platform/companies/${c.id}/administrators/${c.administrators[0].id}/invite`,{})).status,200);
 assert.equal((await post(`/platform/companies/${c.id}/administrators/${c.administrators[0].id}/invite`,{},porto)).status,403);
 assert.equal((await post(`/platform/companies/company-lisbon/administrators/${c.administrators[0].id}/invite`,{})).status,404);
});
test('tenant app sign-in uses a bearer session and excludes other workspaces',async()=>{
  const mobile=(token, company='company-lisbon')=>({
    Authorization:`Bearer ${token}`,'X-Company-Id':company,
  });
  const tenant=await post('/auth/demo',{email:'morador@demo.homealone.pt',client:'resident-app'},null);
  assert.equal(tenant.status,200);
  assert.equal(tenant.response.headers.get('set-cookie'),null);
  assert.match(tenant.data.token,/^resident_[a-f0-9]{64}$/);
  assert.equal((await post('/auth/demo',{email:'gestor@demo.homealone.pt',client:'resident-app'},null)).status,403);

  const password='tenant-test-fixture-password';
  const php='require "src/bootstrap.php"; $d=database(); $d->run("UPDATE users SET password_hash=?,platform_admin=1 WHERE id=?", [password_hash($argv[1], PASSWORD_DEFAULT), "u-resident"]); $d->insert("memberships",["company_id"=>"company-porto","user_id"=>"u-resident","role"=>"MANAGER"]);';
  execFileSync('php',['-r',php,password],{cwd:root,env:{...process.env,DB_PATH:db,DB_MYSQL_DSN:''}});
  const loginResult=await post('/auth/login',{email:'morador@demo.homealone.pt',password,client:'resident-app'},null);
  assert.equal(loginResult.status,200);
  assert.equal(loginResult.response.headers.get('set-cookie'),null);
  const token=loginResult.data.token;
  const headers=mobile(token);
  const identity=await req('/auth/me',{headers},null);
  assert.deepEqual(identity.data.companies.map(c=>[c.id,c.role]),[['company-lisbon','RESIDENT']]);
  assert.equal(identity.data.user.platform_admin,false);
  assert.equal((await req('/platform',{headers},null)).status,403);
  assert.equal((await req('/properties',{headers:mobile(token,'company-porto')},null)).status,403);
  assert.ok((await req('/contracts',{headers},null)).data.every(c=>c.id==='ct-ines'));
  assert.equal((await req('/contracts/ct-miguel',{headers},null)).status,404);
  assert.equal((await req('/auth/logout',{method:'POST',headers},null)).status,200);
  assert.equal((await req('/auth/me',{headers},null)).status,401);
});
test('no unexpected PHP warnings or fatal errors during requests',()=>{assert.doesNotMatch(stderr,/PHP (Warning|Fatal|Parse|Deprecated)/);assert.doesNotMatch(stderr,/HomeAlone error/);});
