import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
const roots=['src','database/migrations','bin','vendor','deploy','docs','composer.json','composer.lock','.env.example','.htaccess','public/app.html','public/openapi.json','public/docs.html','public/.htaccess','public/brand'];
const html=await fs.readFile('public/app.html','utf8');
roots.push(...[...html.matchAll(/(?:src|href)="(\/assets\/[^\"]+)"/g)].map(m=>m[1].slice(1)).map(p=>'public/'+p));
const files={};
async function inspect(file){
 const stat=await fs.lstat(file);if(stat.isSymbolicLink())throw Error('Symlink in deliverable: '+file);
 if(stat.isDirectory()){for(const name of (await fs.readdir(file)).sort())await inspect(path.posix.join(file.replaceAll('\\','/'),name));}
 else if(file!=='deploy/artifact-manifest.json'){
  let bytes=await fs.readFile(file);
  // Git normalizes text to LF on Linux. Hash the same canonical bytes on both hosts.
  if(!bytes.includes(0))bytes=Buffer.from(bytes.toString('latin1').replaceAll('\r\n','\n'),'latin1');
  files[file.replaceAll('\\','/')]=crypto.createHash('sha256').update(bytes).digest('hex');
 }
}
for(const entry of roots)await inspect(entry);
await fs.writeFile('deploy/artifact-manifest.json',JSON.stringify({format:1,files},null,2)+'\n');
console.log(`cPanel deliverable: ${Object.keys(files).length} verified files.`);
