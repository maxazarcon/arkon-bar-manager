import {AuthorizationError,OAuthAuthorizationServer} from '@cloudflare/workers-oauth-provider';
import {env,WorkerEntrypoint} from 'cloudflare:workers';

const SCOPES=['mcp:read','mcp:write','offline_access'];
const server=new OAuthAuthorizationServer({
  issuer:env.ISSUER,
  resources:[env.MCP_SERVER_URL],
  scopesSupported:SCOPES,
  clientIdMetadataDocumentEnabled:true,
  clientRegistrationEndpoint:'/oauth/register'
});

const headers={
  'content-type':'text/html; charset=utf-8',
  'cache-control':'no-store',
  'x-content-type-options':'nosniff',
  'referrer-policy':'no-referrer',
  'content-security-policy':"default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
};
const html=(body,status=200)=>new Response('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Slim\'s Manager authorization</title><main style="max-width:480px;margin:5rem auto;padding:1.5rem;background:#161616;color:#eee;font:16px system-ui;border-radius:14px">'+body+'</main></html>',{status,headers});
function secureEqual(input,expected){
  const a=new TextEncoder().encode(input);
  const b=new TextEncoder().encode(expected);
  return a.byteLength===b.byteLength && crypto.subtle.timingSafeEqual(a,b);
}
async function authorize(request,env){
  if(!env.AUTH_LOGIN_SECRET||env.AUTH_LOGIN_SECRET.length<24){
    return html('<h1>Not configured</h1><p>Sign-in has not been enabled by the site owner.</p>',503);
  }
  const url=new URL(request.url);
  if(request.method!=='GET'&&request.method!=='POST')return html('Method not allowed',405);
  // Parse the canonical GET query even for form submissions.
  let auth;
  try{
    auth=await server.getOAuthApi(env).parseAuthRequest(new Request(url.toString(),{method:'GET'}));
  }catch(err){
    if(err instanceof AuthorizationError){
      if(err.redirectTo)return Response.redirect(err.redirectTo,302);
      return html('<h1>Invalid authorization request</h1><p>Return to ChatGPT and reconnect the plugin.</p>',400);
    }
    throw err;
  }
  if(request.method==='GET'){
    return html(`<h1>Connect Slim's Event Manager</h1>
    <p>Authorize ChatGPT to use your private event manager.</p>
    <p>Requested permissions: ${auth.scope.map(s=>s.replaceAll('<','&lt;')).join(', ')}</p>
    <form method="post" action="${url.pathname+url.search}">
      <label>Owner authorization passphrase<br><input name="passphrase" type="password" autocomplete="off" required style="box-sizing:border-box;width:100%;padding:.7rem;margin:.6rem 0"></label>
      <button name="approve" value="yes" type="submit" style="padding:.7rem 1rem">Approve connection</button>
    </form>`);
  }
  if(request.headers.get('origin')!==url.origin)return html('Invalid form origin',403);
  if(Number(request.headers.get('content-length')||0)>2048)return html('Form too large',413);
  const form=await request.formData();
  if(form.get('approve')!=='yes'||typeof form.get('passphrase')!=='string'||
     !secureEqual(form.get('passphrase'),env.AUTH_LOGIN_SECRET)){
    return html('<h1>Access denied</h1><p>Return to ChatGPT and try connecting again.</p>',403);
  }
  const {redirectTo}=await server.getOAuthApi(env).completeAuthorization({
    request:auth,
    userId:'slims-owner',
    metadata:{},
    scope:auth.scope.filter(s=>SCOPES.includes(s)),
    props:{userId:'slims-owner'}
  });
  return Response.redirect(redirectTo,302);
}
export default class AuthServer extends WorkerEntrypoint{
  fetch(request){
    if(new URL(request.url).pathname==='/authorize')return authorize(request,this.env);
    return server.fetch(request,this.env,this.ctx);
  }
  validateToken(resource,token){
    return server.validateToken(resource,token,this.env);
  }
}
