import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';
const root=process.cwd();const db=path.join(root,'.local','http-tests',`test-${Date.now()}.sqlite`);const port=18082;const base=`http://127.0.0.1:${port}`;let server,admin,resident,porto,tech,createdProperty,contract,charge,doc;let stderr='';
async function req(url,options={},session=admin,company='company-lisbon'){const response=await fetch(base+'/api/v1'+url,{...options,headers:{...(options.body instanceof FormData?{}:{'Content-Type':'application/json'}),...(session?{'Cookie':session.cookie,'X-CSRF-Token':session.csrf,'X-Company-Id':company}:{}),...options.headers}});const content=await response.text();let data;try{data=JSON.parse(content);}catch{data=content;}return {response,data,status:response.status};}
const post=(url,data,session=admin,company)=>req(url,{method:'POST',body:JSON.stringify(data)},session,company);
async function login(email){const {response,data,status}=await post('/auth/demo',{email},null);assert.equal(status,200,JSON.stringify(data));return {cookie:response.headers.get('set-cookie').split(';')[0],csrf:data.csrf};}
before(async()=>{await mkdir(path.dirname(db),{recursive:true});const env={...process.env,DB_PATH:db,APP_ENV:'test',DEMO_MODE:'true',COOKIE_SECURE:'false',APP_URL:base,STRIPE_SECRET_KEY:'',RESEND_API_KEY:''};execFileSync('php',['bin/console.php','migrate'],{cwd:root,env});execFileSync('php',['bin/console.php','seed'],{cwd:root,env});server=spawn('php',['-S',`127.0.0.1:${port}`,'-t','public','public/router.php'],{cwd:root,env,stdio:['ignore','ignore','pipe']});server.stderr.on('data',chunk=>{stderr+=chunk.toString();});for(let i=0;i<50;i++){try{if((await fetch(base+'/api/v1/health')).ok)break;}catch{}await new Promise(r=>setTimeout(r,50));}admin=await login('gestor@demo.homealone.pt');resident=await login('morador@demo.homealone.pt');porto=await login('gestor@demo.porto.pt');tech=await login('tecnico@demo.homealone.pt');});
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

test('recurring cleaning supports assigned completion and moves one visit across months', async () => {
  const input = { property_id: 'p-estrela', assignee_id: 'u-tech', start_date: '2033-01-24',
    end_date: '2033-02-01', interval_days: 7, visit_time: '10:00' };
  const agenda = (period, session = admin, company) => req('/cleaning?period=' + period, {}, session, company);
  assert.equal((await post('/cleaning/plans', input, tech)).status, 403);
  assert.equal((await post('/cleaning/plans', { ...input, assignee_id: 'u-porto' })).status, 422);
  assert.equal((await post('/cleaning/plans', { ...input, property_id: 'p-porto' })).status, 404);
  assert.equal((await post('/cleaning/plans', { ...input, interval_days: 0 })).status, 422);
  assert.equal((await post('/cleaning/plans', { ...input, end_date: '2033-01-01' })).status, 422);
  const created = await post('/cleaning/plans', input);
  assert.equal(created.status, 200, JSON.stringify(created.data));
  const plan = created.data.id;
  const visits = result => result.data.visits.filter(visit => visit.plan_id === plan);
  const update = (day, data, session = admin, company) => req(`/cleaning/plans/${plan}/visits/${day}`, {
    method: 'PATCH', body: JSON.stringify(data),
  }, session, company);
  assert.deepEqual(visits(await agenda('2033-01', tech)).map(v => v.scheduled_date), ['2033-01-24', '2033-01-31']);
  assert.equal((await agenda('2033-01', resident)).status, 403);
  assert.deepEqual(visits(await agenda('2033-01', porto, 'company-porto')), []);
  assert.equal((await update('2033-01-24', { action: 'COMPLETE' }, porto, 'company-porto')).status, 404);
  assert.equal((await update('2033-01-25', { action: 'COMPLETE' })).status, 404);
  const move = { action: 'RESCHEDULE', scheduled_date: '2033-02-02' };
  assert.equal((await update('2033-01-31', move, tech)).status, 403);
  assert.equal((await update('2033-01-31', move)).status, 200);
  assert.deepEqual(visits(await agenda('2033-01')).map(v => v.scheduled_date), ['2033-01-24']);
  const [moved] = visits(await agenda('2033-02', tech));
  assert.equal(moved.occurrence_date, '2033-01-31');
  assert.equal(moved.scheduled_date, '2033-02-02');
  const completed = await update(moved.occurrence_date, { action: 'COMPLETE' }, tech);
  assert.equal(completed.status, 200);
  assert.equal(completed.data.completed_by, 'u-tech');
  const repeated = await update(moved.occurrence_date, { action: 'COMPLETE' });
  assert.equal(repeated.data.completed_by, 'u-tech');
  assert.equal(repeated.data.completed_at, completed.data.completed_at);
  assert.equal((await update(moved.occurrence_date, move)).status, 409);
  const [history] = visits(await agenda('2033-02'));
  assert.equal(history.completed_by_name, 'João Ferreira');
  assert.equal(history.completed_at, completed.data.completed_at);
});
