import {OAuthResourceServer,insufficientScope} from '@cloudflare/workers-oauth-provider';
import {env} from 'cloudflare:workers';
import bridge from './index.mjs';

const writeTools=new Set(['create_event_draft','update_event_draft','upload_flyer','publish_event']);
const allowedTools=new Set(['connection_status','list_events','get_event','list_categories','create_event_draft','update_event_draft','upload_flyer','preview_event','publish_event']);

// OAuth token validation occurs BEFORE the legacy MCP bridge is invoked.
// Requests cannot reach WordPress with an unverified OAuth access token.
export default new OAuthResourceServer({
  resourceMetadata:{
    resource:env.MCP_SERVER_URL,
    authorization_servers:[env.AUTH_SERVER_URL]
  },
  requiredScopes:['mcp:read'],
  validateToken:(env)=>env.AUTH_SERVER.validateToken,
  handler:{
    async fetch(request,env,ctx){
      if(!ctx.auth?.scope?.includes('mcp:read')){
        return insufficientScope(ctx.auth,['mcp:read']);
      }
      const url=new URL(request.url);
      if(url.pathname==='/health'){
        return new Response(JSON.stringify({service:'slims-event-mcp',oauth:true}),{
          headers:{'content-type':'application/json','cache-control':'no-store'}
        });
      }
      if(url.pathname!=='/mcp')return new Response('Not found',{status:404});
      if(request.method==='POST'){
        let rpc;
        try{rpc=await request.clone().json();}catch{return new Response('Invalid JSON',{status:400});}
        const name=rpc?.params?.name;
        if(rpc?.method==='tools/call'){
          if(!allowedTools.has(name))return new Response('Unknown tool',{status:400});
          if(writeTools.has(name)&&!ctx.auth.scope.includes('mcp:write')){
            return insufficientScope(ctx.auth,['mcp:write']);
          }
        }
      }
      if(typeof env.MCP_API_KEY!=='string'||env.MCP_API_KEY.length<24){
        return new Response('Service not provisioned',{status:503});
      }
      // The legacy bridge uses a defense-in-depth local bearer check.
      // Only this OAuth-validated handler can provide the internal key.
      const headers=new Headers(request.headers);
      headers.set('authorization','Bearer '+env.MCP_API_KEY);
      return bridge.fetch(new Request(request,{headers}),env);
    }
  }
});
