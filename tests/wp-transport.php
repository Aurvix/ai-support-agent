<?php
// Real WP HTTP API/REST pipeline, synthetic response bodies only. No external transport adapter.
$savedTransportSettings=get_option('aisa_settings',array());
$skipMCP=getenv('AISA_DEFER_MCP')==='1';
update_option('aisa_settings',array_merge(AISA_Agent::defaults(),array('enabled'=>1,'memory'=>1,'mcp'=>$skipMCP?0:1,'provider'=>'local','model_mode'=>'manual','endpoint'=>'https://protocol.fixture.test/chat','model'=>'synthetic','hourly_limit'=>100)));
$GLOBALS['transport_http_calls']=0;
add_filter('pre_http_request',function($pre,$args,$url) {
    if ($url!=='https://protocol.fixture.test/chat' || !isset($GLOBALS['transport_fixture'])) { return $pre; }
    $GLOBALS['transport_http_calls']++;
    $fixture=$GLOBALS['transport_fixture'];
    if (is_wp_error($fixture)) { return $fixture; }
    return array('headers'=>array(),'response'=>array('code'=>$fixture['status'],'message'=>'Synthetic'),'body'=>$fixture['body'],'cookies'=>array());
},PHP_INT_MAX,3);
require __DIR__.'/transport-contract.php';
transport_contract('verify',function($fixture) {
    $GLOBALS['transport_fixture']=$fixture; $before=$GLOBALS['transport_http_calls'];
    $result=agent_method('complete',array(array('role'=>'user','content'=>'Synthetic public question')));
    verify($GLOBALS['transport_http_calls']===$before+1,'Actual WP HTTP API gets one intercepted attempt, no retry');
    unset($GLOBALS['transport_fixture']); return $result;
},$skipMCP?null:function($raw) {
    $before=$GLOBALS['transport_http_calls'];
    $request=new WP_REST_Request('POST','/aisa/v1/mcp'); $request->set_body($raw);
    $result=AISA_Agent::mcp($request);
    verify($GLOBALS['transport_http_calls']===$before,'Real read-only MCP invokes no model transport');
    return array('data'=>$result->get_data(),'status'=>$result->get_status(),'headers'=>$result->get_headers());
});
if (!$skipMCP) {
function api_mcp($raw,$uid=1,$nonce='valid') {
    global $wp_rest_auth_cookie;
    wp_set_current_user($uid); $wp_rest_auth_cookie=$uid>0;
    unset($_REQUEST['_wpnonce'],$_SERVER['HTTP_X_WP_NONCE']);
    if ($uid && $nonce==='valid') { $_SERVER['HTTP_X_WP_NONCE']=wp_create_nonce('wp_rest'); }
    elseif ($nonce==='invalid') { $_SERVER['HTTP_X_WP_NONCE']='synthetic-invalid'; }
    $server=rest_get_server(); $error=$server->check_authentication();
    if (is_wp_error($error)) { return rest_convert_error_to_response($error); }
    $request=new WP_REST_Request('POST','/aisa/v1/mcp'); $request->set_header('Content-Type','application/json'); $request->set_body($raw);
    return $server->dispatch($request);
}
$ping='{"jsonrpc":"2.0","id":0,"method":"ping"}';
verify(api_mcp($ping,0)->get_status()===401,'Real MCP rejects anonymous principal');
verify(api_mcp($ping,2)->get_status()===403,'Real MCP rejects customer capability');
verify(api_mcp($ping,1,'missing')->get_status()===401,'Missing MCP cookie nonce loses principal');
verify(api_mcp($ping,1,'invalid')->get_status()===403,'Real MCP invalid nonce denied');
verify(api_mcp($ping)->get_status()===200,'Real MCP admin and valid nonce can ping');
$s=get_option('aisa_settings'); $s['mcp']=0; update_option('aisa_settings',$s);
verify(api_mcp($ping)->get_status()===403,'Real disabled MCP denied');
$s['mcp']=1; update_option('aisa_settings',$s);
$oldHttps=$_SERVER['HTTPS']; $oldPort=$_SERVER['SERVER_PORT']; $_SERVER['HTTPS']='off'; $_SERVER['SERVER_PORT']='80';
verify(api_mcp($ping)->get_status()===403,'Real MCP permission requires HTTPS');
$_SERVER['HTTPS']=$oldHttps; $_SERVER['SERVER_PORT']=$oldPort;
verify(api_mcp('{')->get_status()===400,'Real REST parser rejects malformed JSON before dispatch');
$notification=api_mcp('{"jsonrpc":"2.0","method":"notifications/initialized"}');
$request=new WP_REST_Request('POST','/aisa/v1/mcp');
verify($notification->get_status()===202 && apply_filters('rest_pre_serve_request',false,$notification,$request,rest_get_server())===true,'Actual registered WP serve filter suppresses null notification body');
$request=new WP_REST_Request('POST','/aisa/v1/chat');
verify(apply_filters('rest_pre_serve_request',false,$notification,$request,rest_get_server())===false,'MCP empty-body filter leaves other endpoints alone');
verify(apply_filters('rest_pre_serve_request',false,api_mcp($ping),new WP_REST_Request('POST','/aisa/v1/mcp'),rest_get_server())===false,'MCP request results still serialize normally');

// Same approved-source gate through the real MCP REST route.
$tool='{"jsonrpc":"2.0","id":"gate","method":"tools/call","params":{"name":"search_knowledge","arguments":{"query":"Quartzpolicy"}}}';
approve_source($record);
verify(str_contains(wp_json_encode(api_mcp($tool)->get_data()),'Quartzpolicy changed after review'),'Real MCP exposes explicitly reapproved current fact');
approve_source($record,'revoke');
verify(json_decode(api_mcp($tool)->get_data()['result']['content'][0]['text'],true)===array(),'Real MCP revocation immediately excludes source');
approve_source($record); wp_update_post(array('ID'=>$page,'post_content'=>'Quartzpolicy unapproved revision'));
verify(json_decode(api_mcp($tool)->get_data()['result']['content'][0]['text'],true)===array(),'Real MCP excludes changed source without new approval');

}
// Rejected model responses must not commit memory even via the real chat route.
wp_set_current_user(2); $transportScope=AISA_Memory::scope(); $transportSession=str_repeat('d',32);
foreach (array('{"choices":[{"finish_reason":"length","message":{"content":"sensitive-provider-marker"}}]}','{"choices":[{"message":{"content":"sensitive-provider-marker","refusal":"no"}}]}','{"error":{"message":"sensitive-provider-marker"}}') as $body) {
    $context=AISA_Memory::open($transportSession,$transportScope); $before=get_transient($context['key']);
    $GLOBALS['transport_fixture']=array('status'=>200,'body'=>$body);
    $failure=api('chat',2,array('message'=>'Synthetic failed turn','remember'=>true,'session'=>$transportSession,'scope'=>$transportScope));
    verify($failure->get_status()===503 && !str_contains(wp_json_encode($failure->get_data()),'sensitive-provider-marker'),'Actual chat returns redacted incomplete/refusal/error failure');
    verify(get_transient($context['key'])===$before,'Rejected provider answer cannot change real memory');
    unset($GLOBALS['transport_fixture']);
}
$audit=get_posts(array('post_type'=>'aisa_audit','post_status'=>'private','posts_per_page'=>-1));
verify(!str_contains(wp_json_encode($audit),'sensitive-provider-marker'),'Real audit excludes upstream error and answer text');
update_option('aisa_settings',$savedTransportSettings);
