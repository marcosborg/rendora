import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
const root=process.cwd();
await fs.mkdir(path.join(root,'.local'),{recursive:true});
const home=await fs.mkdtemp(path.join(os.tmpdir(),'rendora-cpanel-test-'));
const privateDir=path.join(home,'rendora'),publicDir=path.join(home,'public_html','rendora');
const env={...process.env};
// A deploy task uses its own interpreter, just like cPanel's task runner.
function deploy(target=home){return execFileSync('php',['-r',"require $argv[1].'/deploy/cpanel.php';try{echo json_encode(rendoraDeploy($argv[1],$argv[2]));echo '\nDeploy concluído';}catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(1);}",root,target],{cwd:root,env,encoding:'utf8',stdio:['ignore','pipe','pipe']});}
function php(code,args=[]){return execFileSync('php',['-r',code,...args],{cwd:root,env,encoding:'utf8'});}
let server;after(()=>server?.kill());
test('deployment includes dependencies and assets while excluding runtime data from Git',async()=>{
 await fs.mkdir(path.join(home,'public_html'));await fs.writeFile(path.join(home,'public_html','index.html'),'Existing unrelated site');
 const out=deploy();assert.match(out,/Deploy concluído/);
 for(const p of ['vendor/autoload.php','src/Application.php','src/Installer.php','public/app.html','storage/install.key'])await fs.access(path.join(privateDir,p));
 assert.equal((await fs.readFile(path.join(privateDir,'storage/install.key'),'utf8')).length,64);
 await assert.rejects(fs.access(path.join(privateDir,'.env')));await assert.rejects(fs.access(path.join(privateDir,'storage/homealone.sqlite')));
 await assert.rejects(fs.access(path.join(publicDir,'vendor')));await assert.rejects(fs.access(path.join(publicDir,'.git')));
 assert.equal(await fs.readFile(path.join(home,'public_html/index.html'),'utf8'),'Existing unrelated site');
 const html=await fs.readFile(path.join(privateDir,'public/app.html'),'utf8');
 for(const match of html.matchAll(/(?:src|href)="(\/assets\/[^\"]+)"/g))await fs.access(path.join(publicDir,match[1].slice(1)));
});
test('deployment refuses existing websites and path traversal',async()=>{
 const other=await fs.mkdtemp(path.join(os.tmpdir(),'rendora-cpanel-refuse-test-'));await fs.mkdir(path.join(other,'public_html/rendora'),{recursive:true});
 await fs.writeFile(path.join(other,'public_html/rendora/index.html'),'Keep me');
 assert.throws(()=>deploy(other),/não geridos/);
 assert.equal(await fs.readFile(path.join(other,'public_html/rendora/index.html'),'utf8'),'Keep me');
 await fs.writeFile(path.join(other,'.rendora-deploy.json'),JSON.stringify({private_path:'../outside',public_path:'public_html/rendora'}));
 assert.throws(()=>deploy(other),/caminho relativo seguro/);
});
test('browser installer rejects plain HTTP and invalid key or CSRF; creates only global admin',async()=>{
 // Local test router emulates the HTTPS metadata Apache supplies. No TLS claim is made.
 const router=path.join(home,'test-router.php');
 await fs.writeFile(router,`<?php
 if(isset($_SERVER['HTTP_X_TEST_TLS']))$_SERVER['HTTPS']='on';
 require ${JSON.stringify(path.join(publicDir,'index.php').replaceAll('\\','/'))};`);
 server=spawn('php',['-S','127.0.0.1:18086',router],{cwd:publicDir,env,stdio:'ignore'});
 const url='http://127.0.0.1:18086';let response;
 for(let i=0;i<80;i++){try{response=await fetch(url);break;}catch{await new Promise(r=>setTimeout(r,50));}}
 assert.equal(response.status,403);
 response=await fetch(url,{headers:{'X-Test-TLS':'1'}});assert.equal(response.status,200);
 const html=await response.text();assert.match(html,/Chave de instalação/);assert.match(html,/noindex/);
 const csrf=html.match(/name="csrf" value="([a-f0-9]+)"/)[1];const cookie=response.headers.get('set-cookie').split(';')[0];
 const key=await fs.readFile(path.join(privateDir,'storage/install.key'),'utf8');
 const form={csrf,key,url:'https://127.0.0.1:18086',name:'Administrador de Teste',email:'admin@example.test',password:'CpanelTestPassword123!',confirmation:'CpanelTestPassword123!'};
 const post=data=>fetch(url,{method:'POST',headers:{'X-Test-TLS':'1','Content-Type':'application/x-www-form-urlencoded',Cookie:cookie},body:new URLSearchParams(data)});
 assert.equal((await post({...form,csrf:'invalid'})).status,403);
 assert.equal((await post({...form,key:'wrong'})).status,403);
 assert.equal((await post({...form,url:'https://another.example.test'})).status,422);
 await assert.rejects(fs.access(path.join(privateDir,'.env')));
 response=await post(form);assert.equal(response.status,200);assert.match(await response.text(),/Instalação concluída/);
 const config=await fs.readFile(path.join(privateDir,'.env'),'utf8');assert.match(config,/DEMO_MODE=false/);assert.match(config,/COOKIE_SECURE=true/);assert.doesNotMatch(config,/CpanelTestPassword/);
 const counts=php(`$db=new PDO('sqlite:'.$argv[1]);echo json_encode($db->query('SELECT (SELECT COUNT(*) FROM users) users,(SELECT COUNT(*) FROM companies) companies,(SELECT platform_admin FROM users LIMIT 1) admin')->fetch(PDO::FETCH_ASSOC));`,[path.join(privateDir,'storage/homealone.sqlite')]);
 assert.deepEqual(JSON.parse(counts),{users:1,companies:0,admin:1});
 await assert.rejects(fs.access(path.join(privateDir,'storage/install.key')));
 response=await fetch(url,{headers:{'X-Test-TLS':'1'}});assert.match(await response.text(),/assets\/app-/);
 response=await post(form);assert.doesNotMatch(await response.text(),/Instalação concluída/);
 server.kill();server=null;
});
test('redeploy backs up SQLite and preserves config uploads and cPanel PHP handlers',async()=>{
 const config=await fs.readFile(path.join(privateDir,'.env'),'utf8');
 await fs.mkdir(path.join(privateDir,'storage/uploads'),{recursive:true});await fs.writeFile(path.join(privateDir,'storage/uploads/keep.txt'),'Private attachment');
 const rules=await fs.readFile(path.join(publicDir,'.htaccess'),'utf8');await fs.appendFile(path.join(publicDir,'.htaccess'),'\n# Hosting PHP handler retained\n');
 const out=deploy();assert.match(out,/pre-deploy/);
 assert.equal(await fs.readFile(path.join(privateDir,'.env'),'utf8'),config);
 assert.equal(await fs.readFile(path.join(privateDir,'storage/uploads/keep.txt'),'utf8'),'Private attachment');
 assert.equal(await fs.readFile(path.join(publicDir,'.htaccess'),'utf8'),rules+'\n# Hosting PHP handler retained\n');
 const backups=await fs.readdir(path.join(privateDir,'storage/backups'));assert.equal(backups.length,1);
 assert.equal(php(`$db=new PDO('sqlite:'.$argv[1]);echo $db->query('PRAGMA integrity_check')->fetchColumn();`,[path.join(privateDir,'storage/backups',backups[0])]),'ok');
 assert.equal(php(`$db=new PDO('sqlite:'.$argv[1]);echo $db->query('SELECT COUNT(*) FROM users')->fetchColumn();`,[path.join(privateDir,'storage/homealone.sqlite')]),'1');
 await assert.rejects(fs.access(path.join(privateDir,'storage/install.key')));
});
test('runtime lock prevents deployment while requests or cron hold it',()=>{
 const code=`$h=fopen($argv[1].'/storage/runtime.lock','c');flock($h,LOCK_SH);require $argv[2];try{rendoraDeploy($argv[3],$argv[4]);echo 'unexpected';}catch(Throwable $e){echo $e->getMessage();}`;
 const result=php(code,[privateDir,path.join(root,'deploy/cpanel.php'),root,home]);assert.match(result,/ocupada/);
});
