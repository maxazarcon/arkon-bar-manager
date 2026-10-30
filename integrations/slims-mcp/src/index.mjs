// Arkon Event Manager MCP bridge. Deploy as a Cloudflare Worker (module syntax).
// Credentials are Cloudflare secrets: MCP_API_KEY, WP_USERNAME, WP_APP_PASSWORD.
const SITE = 'https://slimsdivebar.com';
const JSON_HEADERS = {'content-type':'application/json; charset=utf-8','cache-control':'no-store','x-content-type-options':'nosniff'};
const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const writable = (fields) => ({type:'object',properties:fields,required:Object.keys(fields).filter(k=>fields[k].required).map(k=>k)});
const s=(description,required=false)=>({type:'string',description,...(required?{required:true}:{})});
const n=(description)=>({type:'integer',description});
const fields={
  title:s('Event title',true),date:s('Local event date YYYY-MM-DD',true),
  start_time:s('Local time HH:mm (24h), e.g. 13:00',true),
  end_time:s('Local HH:mm end, rolls into following day when earlier than start; or close',true),
  description:s('Optional description in plain text or safe HTML'),
  excerpt:s('Short description for calendar listings'),
  cover:s('Cover charge: number as text, none for no cover, omit if unknown'),
  category_ids:{type:'array',items:{type:'integer'},description:'Existing abm_category term IDs'},
  media_id:n('WordPress Media Library attachment ID for featured image')
};
const TOOL_SPEC=[
  {name:'connection_status',description:'Check if the WordPress connection is configured and accessible.',inputSchema:writable({}),annotations:{readOnlyHint:true}},
  {name:'list_events',description:'Find existing Slim\'s events, including drafts, before creating duplicates.',inputSchema:writable({search:s('Optional text search'),status:{type:'string',enum:['publish','draft','pending','future','private'],description:'Default publish'},per_page:n('Maximum 1-100, default 30')}),annotations:{readOnlyHint:true}},
  {name:'get_event',description:'Read an existing event, including metadata and locked occurrence information.',inputSchema:writable({id:{...n('Event post ID'),required:true}}),annotations:{readOnlyHint:true}},
  {name:'list_categories',description:'Find available event category IDs.',inputSchema:writable({}),annotations:{readOnlyHint:true}},
  {name:'create_event_draft',description:'Create a genuine unpublished WordPress event with proper date, time, metadata and optional flyer. Never publishes.',inputSchema:writable(fields)},
  {name:'update_event_draft',description:'Update an existing draft only. Does not publish or edit live events.',inputSchema:writable({id:{...n('Draft post ID'),required:true},...Object.fromEntries(Object.entries(fields).map(([k,v])=>[k,{...v,required:false}]))})},
  {name:'upload_flyer',description:'Upload an image to WordPress Media Library from a base64-encoded jpeg, png or webp, optionally attach it to a draft event.',inputSchema:writable({filename:s('Image filename ending .jpg, .jpeg, .png or .webp',true),mime_type:{type:'string',enum:['image/jpeg','image/png','image/webp'],description:'Image MIME type',required:true},base64_data:s('Raw base64-encoded image, maximum 5 MiB decoded',true),event_id:n('Optional draft event ID to set as its featured media')})},
  {name:'preview_event',description:'Get the real WordPress draft edit/preview URLs and current event metadata. A screenshot requires a logged-in browser; no simulated image.',inputSchema:writable({id:{...n('Draft post ID'),required:true}}),annotations:{readOnlyHint:true}},
  {name:'publish_event',description:'Publish an existing draft after the user explicitly approves. Requires confirmation exactly PUBLISH <id>.',inputSchema:writable({id:{...n('Draft post ID'),required:true},confirmation:s('Must exactly equal PUBLISH followed by a space and this post ID',true)})}
];
for(const t of TOOL_SPEC){for(const k of Object.keys(t.inputSchema.properties)){delete t.inputSchema.properties[k].required;}t.inputSchema.required=(t.name==='create_event_draft'?['title','date','start_time','end_time']:t.name==='update_event_draft'||t.name==='get_event'||t.name==='preview_event'?['id']:t.name==='upload_flyer'?['filename','mime_type','base64_data']:t.name==='publish_event'?['id','confirmation']:[]);t.inputSchema.additionalProperties=false;}
const reply=(obj,status=200,extra={})=>new Response(JSON.stringify(obj),{status,headers:{...JSON_HEADERS,...extra}});
function error(code,message){return {code,message};}
function fail(message){throw new Error(message);}
async function authorized(req,env){
  const secret=env.MCP_API_KEY;
  if(!secret || secret.length<24)return false;
  const supplied=req.headers.get('authorization')||'';
  if(!supplied.startsWith('Bearer '))return false;
  const x=new TextEncoder().encode(supplied.slice(7)),y=new TextEncoder().encode(secret);
  if(x.byteLength!==y.byteLength)return false;
  return crypto.subtle.timingSafeEqual(x,y);
}
function validDate(date){
 if(typeof date!=='string'||!DATE_RE.test(date))return false;
 const d=new Date(date+'T12:00:00Z');return !Number.isNaN(d.getTime())&&d.toISOString().slice(0,10)===date;
}
function validTime(time){return typeof time==='string'&&(TIME_RE.test(time)||time==='close');}
function integer(value){return Number.isSafeInteger(value)&&value>0;}
function base64Auth(env){
 if(!env.WP_USERNAME||!env.WP_APP_PASSWORD)fail('WordPress connection is not configured. Set WP_USERNAME and WP_APP_PASSWORD as Worker secrets.');
 return 'Basic '+btoa(unescape(encodeURIComponent(env.WP_USERNAME+':'+env.WP_APP_PASSWORD)));
}
async function wp(env,path,options={}){
 if(!path.startsWith('/wp-json/'))fail('Invalid WordPress API path');
 const url=new URL(path,SITE);
 const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),14000);
 try{
  const res=await fetch(url,{method:options.method||'GET',headers:{'Authorization':base64Auth(env),'Accept':'application/json',...(options.headers||{}),...(options.body&&!(options.body instanceof Uint8Array)?{'Content-Type':'application/json'}:{})},body:options.body instanceof Uint8Array?options.body:options.body?JSON.stringify(options.body):undefined,signal:controller.signal,redirect:'error'});
  const contentType=res.headers.get('content-type')||'';
  const text=await res.text();
  let data;try{data=JSON.parse(text);}catch{fail('WordPress returned non-JSON response ('+res.status+').');}
  if(!res.ok)fail('WordPress API '+res.status+': '+String(data?.message||'request failed').slice(0,400));
  return data;
 }catch(e){if(e.name==='AbortError')fail('WordPress API timed out');throw e;}finally{clearTimeout(timer);}
}
function assertFields(a,allowed){if(!a||typeof a!=='object'||Array.isArray(a))fail('Arguments must be an object');for(const key of Object.keys(a)){if(!allowed.includes(key))fail('Unsupported input field: '+key);}}
function normalizeEvent(a,creating){
 const keys=Object.keys(fields); assertFields(a,creating?keys:['id',...keys]);
 if(creating&&(!a.title||!a.date||!a.start_time||!a.end_time))fail('Title, date, start_time and end_time are required');
 if(a.title!==undefined&&(typeof a.title!=='string'||!a.title.trim()||a.title.length>200))fail('Invalid title');
 if(a.date!==undefined&&!validDate(a.date))fail('Invalid date, use YYYY-MM-DD');
 if(a.start_time!==undefined&&(!validTime(a.start_time)||a.start_time==='close'))fail('Invalid start time');
 if(a.end_time!==undefined&&!validTime(a.end_time))fail('Invalid end time');
 for(const k of ['description','excerpt','cover'])if(a[k]!==undefined&&(typeof a[k]!=='string'||a[k].length>20000))fail('Invalid '+k);
 if(a.category_ids!==undefined&&(!Array.isArray(a.category_ids)||a.category_ids.length>20||!a.category_ids.every(integer)))fail('Invalid categories');
 if(a.media_id!==undefined&&!integer(a.media_id))fail('Invalid media attachment ID');
 const out={}; if(a.title!==undefined)out.title=a.title.trim();
 if(a.description!==undefined)out.content=a.description;
 if(a.excerpt!==undefined)out.excerpt=a.excerpt;
 if(a.category_ids!==undefined)out.abm_category=a.category_ids;
 if(a.media_id!==undefined)out.featured_media=a.media_id;
 const meta={};for(const [from,to] of [['date','abm_event_date'],['start_time','abm_event_time_start'],['end_time','abm_event_time_end'],['cover','abm_event_cost']])if(a[from]!==undefined)meta[to]=a[from];
 if(Object.keys(meta).length)out.meta=meta;
 return out;
}
function slim(e){
 if(!e||typeof e!=='object')return e;
 return {id:e.id,title:e.title?.raw??e.title?.rendered??'',status:e.status,link:e.link,slug:e.slug,description:e.content?.raw??e.content?.rendered??'',excerpt:e.excerpt?.raw??e.excerpt?.rendered??'',featured_media:e.featured_media,abm_category:e.abm_category,meta:e.meta,abm_occurrences:e.abm_occurrences};
}
async function checkDuplicate(env,a){
 const title=a.title.trim().toLowerCase();
 for(const status of ['publish','draft']){
  const q=new URLSearchParams({context:'edit',status,per_page:'100',search:a.title,_fields:'id,title,meta,status'});
  const list=await wp(env,'/wp-json/wp/v2/abm_event?'+q);
  const collision=list.find(x=>(x.title?.raw||x.title?.rendered||'').trim().toLowerCase()===title&&x.meta?.abm_event_date===a.date);
  if(collision)fail('Duplicate event found: ID '+collision.id+' ('+status+'). Edit the existing event instead.');
 }
}
async function current(env,id){
 if(!integer(id))fail('Positive integer event ID required');
 return wp(env,'/wp-json/wp/v2/abm_event/'+id+'?context=edit');
}
async function tool(name,a,env){
 switch(name){
 case 'connection_status': {
  const status={worker_ready:Boolean(env.MCP_API_KEY),wordpress_credentials_configured:Boolean(env.WP_USERNAME&&env.WP_APP_PASSWORD)};
  if(status.wordpress_credentials_configured){try{const me=await wp(env,'/wp-json/wp/v2/users/me?context=edit');status.wordpress_authenticated=true;status.wp_user_id=me.id;}catch(e){status.wordpress_authenticated=false;status.error=e.message;}}
  return status;
 }
 case 'list_events': {
  assertFields(a,['status','search','per_page']);let {search='',status='publish',per_page=30}=a;
  if(!['publish','draft','pending','future','private'].includes(status))fail('Invalid event status');
  if(typeof search!=='string'||search.length>200)fail('Invalid search');
  per_page=Math.min(100,Math.max(1,Number(per_page)||30));
  const q=new URLSearchParams({context:'edit',status,per_page:String(per_page),_fields:'id,title,status,link,meta,featured_media,abm_category'});
  if(search)q.set('search',search);
  return (await wp(env,'/wp-json/wp/v2/abm_event?'+q)).map(slim);
 }
 case 'get_event': {
  assertFields(a,['id']); const e=await wp(env,'/wp-json/wp/v2/abm_event/'+a.id+'?context=edit&_fields=id,title,status,link,slug,content,excerpt,featured_media,abm_category,meta,abm_occurrences');return slim(e);
 }
 case 'list_categories': {
  assertFields(a,[]);return wp(env,'/wp-json/wp/v2/abm_category?per_page=100&_fields=id,name,slug,count');
 }
 case 'create_event_draft': {
  const body=normalizeEvent(a,true); await checkDuplicate(env,a);
  return slim(await wp(env,'/wp-json/wp/v2/abm_event',{method:'POST',body:{...body,status:'draft'}}));
 }
 case 'update_event_draft': {
  const body=normalizeEvent(a,false);const e=await current(env,a.id);
  if(e.status!=='draft')fail('Refusing to modify a non-draft event');
  if(!Object.keys(body).length)fail('No changes provided');
  return slim(await wp(env,'/wp-json/wp/v2/abm_event/'+a.id,{method:'POST',body}));
 }
 case 'upload_flyer': {
  assertFields(a,['filename','mime_type','base64_data','event_id']);
  if(typeof a.filename!=='string'||!/^[-\w. ]+\.(jpe?g|png|webp)$/i.test(a.filename)||a.filename.length>140)fail('Invalid image filename');
  const extensions={'image/jpeg':/\.jpe?g$/i,'image/png':/\.png$/i,'image/webp':/\.webp$/i};
  if(!extensions[a.mime_type]?.test(a.filename))fail('Image MIME/filename mismatch');
  if(typeof a.base64_data!=='string'||a.base64_data.length>7_000_000||!(/^[A-Za-z0-9+/=]+$/).test(a.base64_data))fail('Invalid base64 image');
  const raw=atob(a.base64_data);if(raw.length>5_242_880)fail('Image exceeds 5 MiB limit');
  const bytes=Uint8Array.from(raw,c=>c.charCodeAt(0));
  const png=bytes.length>8&&[137,80,78,71,13,10,26,10].every((v,i)=>bytes[i]===v);
  const jpg=bytes[0]===255&&bytes[1]===216&&bytes[2]===255;
  const webp=bytes.length>12&&String.fromCharCode(...bytes.slice(0,4))==='RIFF'&&String.fromCharCode(...bytes.slice(8,12))==='WEBP';
  if(!({'image/jpeg':jpg,'image/png':png,'image/webp':webp}[a.mime_type]))fail('Image content does not match declared type');
  if(a.event_id!==undefined){const e=await current(env,a.event_id);if(e.status!=='draft')fail('Can attach flyer only to drafts');}
  const attachment=await wp(env,'/wp-json/wp/v2/media',{method:'POST',headers:{'content-type':a.mime_type,'content-disposition':'attachment; filename="'+a.filename+'"'},body:bytes});
  let updated_event=null;
  if(a.event_id!==undefined){updated_event=slim(await wp(env,'/wp-json/wp/v2/abm_event/'+a.event_id,{method:'POST',body:{featured_media:attachment.id}}));}
  return {media_id:attachment.id,source_url:attachment.source_url,attached_event:updated_event};
 }
 case 'preview_event': {
  assertFields(a,['id']);const e=await current(env,a.id);if(e.status!=='draft')fail('Expected draft event');
  const id=e.id;return {event:slim(e),edit_url:SITE+'/wp-admin/post.php?post='+id+'&action=edit',preview_url:SITE+'/?post_type=abm_event&p='+id+'&preview=true',screenshot_available:false,notice:'WordPress draft preview requires an authenticated WP browser session. This MCP bridge does not falsely generate a screenshot or expose drafts publicly.'};
 }
 case 'publish_event': {
  assertFields(a,['id','confirmation']);
  if(!integer(a.id)||a.confirmation!=='PUBLISH '+a.id)fail('Publishing requires explicit confirmation: PUBLISH '+a.id);
  const existing=await current(env,a.id);if(existing.status!=='draft')fail('Only drafts can be published using this tool');
  if(!validDate(existing.meta?.abm_event_date)||!validTime(existing.meta?.abm_event_time_start)||!validTime(existing.meta?.abm_event_time_end))fail('Event is incomplete and cannot be published');
  return slim(await wp(env,'/wp-json/wp/v2/abm_event/'+a.id,{method:'POST',body:{status:'publish'}}));
 }
 default: fail('Unknown MCP tool: '+name);
 }
}
export default {
 async fetch(req,env){
  const url=new URL(req.url);
  if(url.pathname==='/health'&&req.method==='GET')return reply({service:'slims-event-mcp',configured:Boolean(env.MCP_API_KEY),wp_configured:Boolean(env.WP_USERNAME&&env.WP_APP_PASSWORD)});
  if(url.pathname!=='/mcp')return reply({error:'Not found'},404);
  if(!env.MCP_API_KEY||env.MCP_API_KEY.length<24)return reply({error:'MCP server is not provisioned'},503);
  if(!(await authorized(req,env)))return reply({error:'Unauthorized'},401,{'www-authenticate':'Bearer realm="Slims Event Manager"'});
  if(req.method==='GET')return reply({error:'Use Streamable HTTP POST'},405,{'allow':'POST'});
  if(req.method!=='POST')return reply({error:'Method not allowed'},405,{'allow':'POST'});
  if(Number(req.headers.get('content-length')||0)>7_400_000)return reply({error:'Payload too large'},413);
  let data;try{data=await req.json();}catch{return reply({error:'Invalid JSON'},400);}
  if(!data||typeof data!=='object'||Array.isArray(data)||typeof data.method!=='string')return reply({jsonrpc:'2.0',id:data?.id??null,error:error(-32600,'Invalid JSON-RPC request')},400);
  const id=data.id??null;
  if(data.method.startsWith('notifications/')||id===null)return new Response(null,{status:202,headers:{'cache-control':'no-store'}});
  try{
   let result;
   switch(data.method){
    case 'initialize': result={protocolVersion:'2025-06-18',capabilities:{tools:{listChanged:false}},serverInfo:{name:'slims-event-manager',version:'0.1.0'}};break;
    case 'ping':result={};break;
    case 'tools/list':result={tools:TOOL_SPEC};break;
    case 'tools/call':{
      const name=data.params?.name,args=data.params?.arguments??{};
      try{const value=await tool(name,args,env);result={content:[{type:'text',text:JSON.stringify(value)}],isError:false};}
      catch(e){result={content:[{type:'text',text:e instanceof Error?e.message:'Tool failed'}],isError:true};}
      break;
    }
    default:return reply({jsonrpc:'2.0',id,error:error(-32601,'Method not found')});
   }
   return reply({jsonrpc:'2.0',id,result});
  }catch(e){return reply({jsonrpc:'2.0',id,error:error(-32603,'Internal server error')},500);}
 }
};