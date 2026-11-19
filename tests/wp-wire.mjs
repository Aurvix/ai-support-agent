// Real WordPress front-controller/REST serialization. Anonymous client only, no credential creation.
export async function wireContract(php,{deferMcp=false}={}) {
  const prepared=await php.run({scriptPath:'/wordpress/wire-seed.php',protocol:'https',env:{AISA_DEFER_MCP:deferMcp?'1':''},$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});
  if(prepared.errors) throw new Error('Wire preparation failed');
  const seed=JSON.parse(prepared.text);let checks=0;const cases=[];
  const check=(ok,label)=>{checks++;if(!ok)throw new Error('REST wire assertion: '+label);};
  const request=async(route,{method='POST',body={},scenario='ok',headers={}}={})=>{
    const response=await php.run({scriptPath:'/wordpress/index.php',relativeUri:'/?rest_route=/aisa/v1/'+route,protocol:'https',method,headers:{'Content-Type':'application/json',Accept:'application/json',...headers},...(method==='POST'?{body:new TextEncoder().encode(typeof body==='string'?body:JSON.stringify(body))}:{}),env:{AISA_WIRE_CASE:scenario},$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});
    if(response.errors) throw new Error('Native REST request failed: '+route);
    let data;try{data=JSON.parse(response.text);}catch{throw new Error('Native REST did not serialize JSON: '+route);}
    check((response.headers['content-type']||[]).some(v=>v.startsWith('application/json')),'native JSON content type '+route);
    check(!response.text.includes('synthetic-wire-upstream-private-marker'),'upstream details redacted on wire '+route);
    return {status:response.httpStatusCode,data,headers:response.headers};
  };
  const context=await request('context',{body:{scope:seed.scope}});
  check(context.status===200&&context.data.customer_tickets===true&&context.data.memory_enabled===false,'actual anonymous context request');
  check((context.headers['cache-control']||[]).some(v=>v.includes('private')&&v.includes('no-store')),'context private/no-store header serialized');
  for(const route of ['orders','downloads','tickets/status','tickets/submit',...(deferMcp?[]:['mcp'])]) {
    const response=await request(route,{method:['orders','downloads'].includes(route)?'GET':'POST',body:route==='mcp'?{jsonrpc:'2.0',id:1,method:'ping'}:{scope:seed.scope,topic:'general',confirm:true,request_id:'a'.repeat(32)}});
    check(response.status===401||response.status===403,'native anonymous authorization denies '+route);cases.push({name:route,status:response.status});
  }
  const wrongNonce=await request('context',{body:{scope:seed.scope},headers:{'X-WP-Nonce':'synthetic-invalid'}});
  check(wrongNonce.status===403,'native invalid nonce denied without login');
  const badJson=await request('chat',{body:'{'});check(badJson.status===400&&badJson.data.code==='rest_invalid_json','native malformed JSON rejected before callback');
  const chatBody={message:'Wirequartz policy',scope:seed.scope,session:'b'.repeat(32),remember:false};
  const success=await request('chat',{body:chatBody});
  check(success.status===200&&success.data.reply==='Synthetic serialized answer.'&&success.data.sources.length===1,'actual front-controller chat returns approved public source and intercepted answer');
  check((success.headers['cache-control']||[]).some(v=>v.includes('private')&&v.includes('no-store')),'chat no-store serialized');
  for(const scenario of ['timeout','401','429','503','bad-json','truncated']) {
    const response=await request('chat',{body:chatBody,scenario});
    check(response.status===503&&response.data.code==='provider'&&!JSON.stringify(response.data).includes('Synthetic serialized answer.'),'provider failure serialized generically '+scenario);cases.push({name:'provider-'+scenario,status:response.status});
  }
  const stale=await request('chat',{body:chatBody,scenario:'revoke'});
  check(stale.status===409&&stale.data.code==='knowledge_changed'&&!JSON.stringify(stale.data).includes('Synthetic serialized answer.'),'source revoked during provider work rejects wire answer');
  const noSource=await request('chat',{body:chatBody});check(noSource.status===200&&noSource.data.sources.length===0,'revoked source absent on next HTTP turn');
  const staleScope=await request('context',{body:{scope:'stale-public-scope'}});check(staleScope.status===409,'stale anonymous identity scope rejected');
  if(!deferMcp){const unsupported=await request('mcp',{method:'GET'});check([404,405].includes(unsupported.status),'unsupported MCP GET is not advertised streaming support');}
  const final=await php.run({code:`<?php require '/wordpress/wp-load.php'; $clean=true; foreach(array(1,2,3) as $id) { $clean=$clean && get_userdata($id)->user_pass==='*' && !WP_Session_Tokens::get_instance($id)->get_all() && !get_user_meta($id,'_application_passwords',true); } echo wp_json_encode(array('guest'=>get_current_user_id()===0,'clean'=>$clean,'calls'=>(int)get_option('aisa_wire_calls')));`,protocol:'https',$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443'}});
  if(final.errors) throw new Error('Wire teardown inspection failed');const state=JSON.parse(final.text);
  check(state.guest&&state.clean,'no preset authenticated principal or usable credentials/sessions');
  check(state.calls===9,'exactly one intercepted provider attempt per accepted chat, no retries or unauthorized attempts');
  return {status:'PASS',checks,plugin_version:seed.plugin_version,cases,entrypoint:'native WordPress index.php HTTP/REST serialization',principal:'native anonymous, no preset user',external_transport:'intercepted fixtures only',mcp_deferred:deferMcp,real_login_verified:false,mcp_authenticated_client_verified:false,external_provider_verified:false,credentials_created:false,external_sends:0};
}
