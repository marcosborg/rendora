import { readFile, writeFile } from 'node:fs/promises';
const source = await readFile('src/Application.php','utf8');
const string = {type:'string'}, integer={type:'integer'}, boolean={type:'boolean'}, cents={type:'integer',minimum:0,description:'Montante em cêntimos de euro.'}, date={type:'string',format:'date'}, id={type:'string'}, strings={type:'array',items:string};
const object = properties => ({type:'object',properties});
const ref = name => ({$ref:`#/components/schemas/${name}`});
const schemas = {
 NewPlatformCompany:{...object({name:{type:'string',minLength:2,maxLength:100},slug:{type:'string',minLength:3,maxLength:60,pattern:'^[a-z0-9]+(?:-[a-z0-9]+)*$'},email:{type:'string',format:'email'},admin_name:{type:'string',minLength:2,maxLength:150},admin_email:{type:'string',format:'email'}}),required:['name','slug','email','admin_name','admin_email']},
 PlatformAdministrator:object({id,name:string,email:{type:'string',format:'email'},active:integer,invitation_pending:integer}),
 CreatedCompany:object({id,name:string,slug:string,administrator:ref('PlatformAdministrator')}),
 Error:object({message:string,request_id:string}),
 User:object({id,name:string,email:{type:'string',format:'email'},platform_admin:boolean}),
 Property:object({id,company_id:id,parent_id:{...id,nullable:true},owner_id:{...id,nullable:true},name:string,type:{type:'string',enum:['BUILDING','APARTMENT','ROOM','COMMON']},address:string,city:string,description:string,bedrooms:integer,area:integer,rent_cents:cents,published:integer,photos:strings,amenities:strings,occupied:boolean}),
 Contact:object({id,company_id:id,user_id:{...id,nullable:true},name:string,email:string,phone:string,kind:{type:'string',enum:['LEAD','RESIDENT','OWNER','PROVIDER']},tags:{type:'string',description:'JSON array of labels'},notes:string}),
 Contract:object({id,company_id:id,property_id:id,property_name:string,start_date:date,end_date:{...date,nullable:true},rent_cents:cents,charges_cents:cents,deposit_cents:cents,due_day:{type:'integer',minimum:1,maximum:31},status:{type:'string',enum:['ACTIVE','ENDED','CANCELLED']},notes:string,template:string,residents:{type:'array',items:ref('Contact')}}),
 Charge:object({id,company_id:id,contract_id:id,period:{type:'string',pattern:'^\\d{4}-(0[1-9]|1[0-2])$'},due_date:date,amount_cents:cents,confirmed:integer,paid_cents:integer,balance_cents:integer,status:{type:'string',enum:['PENDING','PROCESSING','PARTIAL','PAID','FAILED','REFUNDED','DRAFT']},overdue:boolean,property_name:string,residents:{type:'array',items:ref('Contact')}}),
 Payment:object({id,charge_id:id,amount_cents:cents,kind:{type:'string',enum:['PAYMENT','REFUND','ADJUSTMENT','DISPUTE','DISPUTE_WON']},method:{type:'string',enum:['MANUAL','STRIPE']},reason:string,idempotency_key:string,external_id:{...string,nullable:true},created_at:string}),
 Ticket:object({id,property_id:id,property_name:string,reporter_id:id,assignee_id:{...id,nullable:true},title:string,description:string,category:string,priority:{type:'string',enum:['LOW','NORMAL','HIGH','URGENT']},status:{type:'string',enum:['NEW','TRIAGE','SCHEDULED','IN_PROGRESS','WAITING','RESOLVED','CLOSED']},due_at:{...date,nullable:true},scheduled_at:{...date,nullable:true},budget_cents:{...cents,nullable:true},budget_approved:integer,final_cost_cents:{...cents,nullable:true},created_at:string}),
 Lead:object({id,contact_id:id,property_id:{...id,nullable:true},name:string,email:string,phone:string,stage:{type:'string',enum:['NEW','QUALIFIED','VISIT','PROPOSAL','CONVERTED','LOST']},source:string,notes:string,visit_at:{...date,nullable:true},task:string,task_due:{...date,nullable:true}}),
 Conversation:object({id,subject:string,property_id:{...id,nullable:true},last_message:{...string,nullable:true},unread:integer,created_at:string}),
 Message:object({id,conversation_id:id,author_id:id,author_name:string,body:string,read_count:integer,created_at:string}),
 Notification:object({id,title:string,body:string,is_read:integer,email_status:string,created_at:string}),
 Document:object({id,name:string,entity_type:string,entity_id:id,mime_type:string,size:integer,created_at:string}),
 Login:{...object({email:{type:'string',format:'email'},password:{type:'string',format:'password'},client:{type:'string',enum:['web','mobile']}}),required:['email','password']},
 Session:object({token:{type:'string',description:'Only returned when client=mobile. Store in OS secure storage.'},csrf:string,user:ref('User'),expires_in:integer}),
 NewContract:{...object({property_id:id,contact_ids:strings,start_date:date,end_date:{...date,nullable:true},rent_cents:cents,charges_cents:cents,deposit_cents:cents,due_day:integer,notes:string,template:string}),required:['property_id','contact_ids','start_date','rent_cents','charges_cents','deposit_cents','due_day']},
 NewProperty:{...object({name:string,type:{type:'string',enum:['BUILDING','APARTMENT','ROOM','COMMON']},parent_id:{...id,nullable:true},owner_id:{...id,nullable:true},address:string,city:string,description:string,bedrooms:integer,area:integer,rent_cents:cents,published:boolean,photos:strings,amenities:strings}),required:['name','type','address','city','bedrooms','area','rent_cents']},
 ManualPayment:{...object({amount_cents:cents,kind:{type:'string',enum:['PAYMENT','ADJUSTMENT']},reason:string,idempotency_key:{type:'string',minLength:8}}),required:['amount_cents','kind','reason','idempotency_key']},
 Dashboard:object({expected_cents:integer,received_cents:integer,overdue_cents:integer,units:integer,occupied:integer,occupancy:{type:'number'},history:{type:'array',items:object({period:string,expected:integer,received:integer})},charges:{type:'array',items:ref('Charge')},expiring:{type:'array',items:ref('Contract')}}),
};
const bodySchemas={
 'POST /auth/login':ref('Login'), 'POST /auth/register':{...object({name:string,email:string,password:{type:'string',minLength:12},company:string,slug:{type:'string',pattern:'^[a-z0-9]+(?:-[a-z0-9]+)*$'}}),required:['name','email','password','company','slug']},
 'POST /auth/forgot':object({email:string}), 'POST /auth/reset':object({token:string,password:{type:'string',minLength:12}}),
 'POST /properties':ref('NewProperty'), 'PATCH /properties/{id}':ref('NewProperty'),
 'POST /contacts':object({name:string,email:string,phone:string,kind:string,tags:strings,notes:string,user_id:id}), 'PATCH /contacts/{id}':object({name:string,email:string,phone:string,kind:string,tags:strings,notes:string,user_id:id}),
 'POST /contracts':ref('NewContract'), 'PATCH /contracts/{id}':object({action:{type:'string',enum:['renew','end','rent','template']},end_date:date,acknowledge_charges:boolean,effective_date:date,rent_cents:cents,template:string}),
 'POST /contracts/{id}/deposits':object({kind:{type:'string',enum:['RECEIVED','RETURNED','DEDUCTED']},amount_cents:cents,reason:string}),
 'POST /contracts/{id}/inspections':object({kind:{type:'string',enum:['ENTRY','EXIT']},notes:string,items:strings}),
 'POST /charges/generate':{...object({period:{type:'string',pattern:'^\\d{4}-(0[1-9]|1[0-2])$'}}),required:['period']},
 'POST /charges/{id}/payments':ref('ManualPayment'), 'POST /payments/{id}/refund':object({amount_cents:cents,reason:string,idempotency_key:string}),
 'POST /expenses':object({property_id:id,ticket_id:id,description:string,amount_cents:cents,date}),
 'POST /tickets':object({property_id:id,title:string,description:string,category:{type:'string',enum:['PLUMBING','ELECTRICITY','APPLIANCE','CLEANING','BUILDING','OTHER']},priority:{type:'string',enum:['LOW','NORMAL','HIGH','URGENT']}}),
 'PATCH /tickets/{id}':object({status:schemas.Ticket.properties.status,priority:schemas.Ticket.properties.priority,assignee_id:id,provider_id:id,due_at:date,scheduled_at:date,budget_cents:cents,budget_approved:boolean,final_cost_cents:cents,note:string}),
 'POST /tickets/{id}/notes':object({body:string,internal:boolean}),
 'POST /leads':object({contact_id:id,property_id:id,notes:string}), 'PATCH /leads/{id}':object({stage:string,notes:string,visit_at:date,task:string,task_due:date}), 'POST /leads/{id}/convert':ref('NewContract'),
 'POST /conversations':object({subject:string,participant_ids:strings,property_id:id,body:string}), 'POST /conversations/{id}/messages':object({body:string}),
 'POST /broadcasts':object({title:string,body:string,user_ids:strings,property_id:id,idempotency_key:string}),
 'PATCH /settings':object({name:string,email:string,phone:string,description:string,color:string,reminder_days:{type:'array',items:integer},email_template:string}),
 'POST /members':object({name:string,email:string,role:string}), 'PATCH /members/{id}':object({role:string,active:boolean}), 'POST /settings/billing':object({plan_id:id}),
 'POST /properties/{id}/photos':object({file:{type:'string',format:'binary'}}),
 'POST /documents':object({entity_type:{type:'string',enum:['property','contract','ticket','conversation']},entity_id:id,file:{type:'string',format:'binary'}}),
 'POST /devices':object({token:string,platform:{type:'string',enum:['ios','android','web']}}),
 'POST /public/{slug}/leads':object({property_id:id,name:string,email:string,phone:string,notes:string,consent:boolean,website:{type:'string',description:'Honeypot; must be empty.'}}),
 'POST /platform/plans':object({name:string,stripe_price_id:string,property_limit:integer,member_limit:integer}),
 'POST /platform/companies':ref('NewPlatformCompany'),
};
const responseMap={'/properties':'Property','/contacts':'Contact','/contracts':'Contract','/charges':'Charge','/tickets':'Ticket','/leads':'Lead','/conversations':'Conversation','/notifications':'Notification','/documents':'Document'};
const paths={};
const matches=[...source.matchAll(/\$route\('(GET|POST|PATCH|DELETE)','([^']+)'/g)].map(m=>[m[1],m[2],true]);
for(const m of source.matchAll(/\$app->(get|post)\('\/api\/v1([^']+)'/g)) matches.push([m[1].toUpperCase(),m[2],false]);
for(const [method,path,scoped] of matches){
 if(path==='/auth/demo')continue;
 const isPublic=path.startsWith('/public/')||path.startsWith('/webhooks/')||['/auth/login','/auth/register','/auth/forgot','/auth/reset','/config','/health'].includes(path);
 let result={type:'object',additionalProperties:true}; const base='/'+path.split('/')[1]; const model=responseMap[base];
 if(model && method==='GET' && path===base) result={type:'array',items:ref(model)};
 else if(model && path===base+'/{id}' && method==='GET') result=ref(model);
 if(path==='/dashboard')result=ref('Dashboard');if(path==='/auth/login'||path==='/auth/register')result=ref('Session');
 if(path==='/platform/companies'&&method==='POST')result=ref('CreatedCompany');
 if(path==='/conversations/{id}/messages'&&method==='GET')result={type:'array',items:ref('Message')};
 if(path==='/charges/{id}')result={allOf:[ref('Charge'),object({movements:{type:'array',items:ref('Payment')}})]};
 const parameters=[...path.matchAll(/\{([^}]+)\}/g)].map(m=>({name:m[1],in:'path',required:true,schema:string}));
 if(scoped)parameters.push({name:'X-Company-Id',in:'header',required:true,schema:string,description:'Must be an active membership of the authenticated user.'});
 if(!isPublic&&method!=='GET')parameters.push({name:'X-CSRF-Token',in:'header',required:false,schema:string,description:'Required for cookie sessions. Not used with mobile Bearer authentication.'});
 if(['/charges','/dashboard','/reports/export'].includes(path)&&method==='GET')for(const name of ['period','property_id','owner_id'])parameters.push({name,in:'query',required:false,schema:string});
 if(path==='/documents'&&method==='GET')for(const name of ['entity_type','entity_id'])parameters.push({name,in:'query',required:true,schema:string});
 if(path.startsWith('/webhooks/'))parameters.push({name:'Stripe-Signature',in:'header',required:true,schema:string});
 const contentType=path==='/reports/export'?'text/csv':path.endsWith('/download')?'application/octet-stream':path.endsWith('/pdf')||path.endsWith('.pdf')?'application/pdf':path==='/public/photos/{id}'?'image/jpeg':'application/json';
 const operation={operationId:method.toLowerCase()+path.replace(/\W+/g,'_'),summary:`${method} ${path}`,tags:[path.split('/')[1]],security:isPublic?[]:[{bearerAuth:[]},{cookieAuth:[]}],parameters,responses:{'200':{description:'Successful response',content:{[contentType]:{schema:contentType==='application/json'?result:{type:'string',format:'binary'}}}},'401':{description:'Authentication required',content:{'application/json':{schema:ref('Error')}}},'403':{description:'Insufficient permissions or invalid CSRF',content:{'application/json':{schema:ref('Error')}}},'404':{description:'Not found in authorized scope',content:{'application/json':{schema:ref('Error')}}},'409':{description:'Conflict or unsafe duplicate operation',content:{'application/json':{schema:ref('Error')}}},'422':{description:'Invalid input',content:{'application/json':{schema:ref('Error')}}},'503':{description:'External integration is not configured',content:{'application/json':{schema:ref('Error')}}}}};
 const body=bodySchemas[`${method} ${path}`];if(body)operation.requestBody={required:true,content:{[path==='/documents'||path==='/properties/{id}/photos'?'multipart/form-data':'application/json']:{schema:body}}};
 if(path.startsWith('/platform'))operation.description='Requires platform_admin. Does not grant the actor access to tenant business data. Cookie writes require CSRF. New or unverified administrators receive a pending invitation; existing verified accounts keep their credentials.';
 if(path==='/platform/companies'&&method==='POST'){operation.responses['201']=operation.responses['200'];delete operation.responses['200'];}
 paths[path]??={};paths[path][method.toLowerCase()]=operation;
}
const spec={openapi:'3.0.3',info:{title:'Rendora API',version:'1.0.0',description:'PHP 8.2 / SQLite. Amounts use integer EUR cents. All business operations enforce membership and role server-side. Browser: HttpOnly session cookie + CSRF. Native app: POST /auth/login with client=mobile, Bearer opaque token (24h expiry), store in OS secure storage. X-Company-Id is mandatory on tenant routes. Re-authenticate on 401. Dates use Europe/Lisbon for business rules. See docs/API.md for permissions and payment lifecycle.'},servers:[{url:'/api/v1'}],components:{securitySchemes:{bearerAuth:{type:'http',scheme:'bearer',bearerFormat:'opaque'},cookieAuth:{type:'apiKey',in:'cookie',name:'ha_session'}},schemas},paths};
await writeFile('public/openapi.json',JSON.stringify(spec,null,2)+'\n');
console.log(`Documented ${matches.filter(x=>x[1]!=='/auth/demo').length} operations.`);
