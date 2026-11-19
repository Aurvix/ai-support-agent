<?php
// Shared contract exercised once with HTTP doubles and again with actual WP HTTP interception.
// This is a synthetic protocol peer, not an external provider or MCP SDK/client.
function transport_contract($assert,$complete,$rpc=null) {
    $answer=function($content='Synthetic answer',$extra=array()) { return wp_json_encode(array('choices'=>array(array_merge(array('message'=>array('role'=>'assistant','content'=>$content),'finish_reason'=>'stop'),$extra)))); };
    $cases=array(
        'normal'=>array($answer(),true),
        'legacy compatible finish omitted'=>array('{"choices":[{"message":{"content":"Synthetic answer"}}]}',true),
        'unicode'=>array($answer('नमस्ते, मदद के लिए तैयार।'),true),
        'HTML-only'=>array($answer('<b></b>'),false),
        'empty'=>array($answer('   '),false),
        'oversized answer'=>array($answer(str_repeat('a',12001)),false),
        'answer boundary'=>array($answer(str_repeat('a',12000)),true),
        'oversized body'=>array(str_repeat(' ',65537),false),
        'truncated JSON'=>array('{"choices":[',false),
        'invalid UTF8'=>array("{\"choices\":\"\xff\"}",false),
        'null body'=>array('null',false),
        'array body'=>array('[]',false),
        'scalar body'=>array('42',false),
        'choices scalar'=>array('{"choices":5}',false),
        'choice scalar'=>array('{"choices":["sensitive-provider-marker"]}',false),
        'message scalar'=>array('{"choices":[{"message":5}]}',false),
        'error plus choices'=>array('{"error":{"message":"sensitive-provider-marker"},"choices":[{"message":{"content":"Synthetic answer"}}]}',false),
        'non-text content'=>array($answer(array(array('type'=>'text','text'=>'sensitive-provider-marker'))),false),
        'refusal'=>array($answer('sensitive-provider-marker',array('message'=>array('content'=>'sensitive-provider-marker','refusal'=>'refused'))),false),
        'tool call'=>array($answer('sensitive-provider-marker',array('message'=>array('content'=>'sensitive-provider-marker','tool_calls'=>array(array('id'=>'x'))))),false),
        'function call'=>array($answer('sensitive-provider-marker',array('message'=>array('content'=>'sensitive-provider-marker','function_call'=>array('name'=>'x')))),false),
        'wrong role'=>array($answer('sensitive-provider-marker',array('message'=>array('role'=>'system','content'=>'sensitive-provider-marker'))),false)
    );
    $cases['null role']=array($answer('sensitive-provider-marker',array('message'=>array('role'=>null,'content'=>'sensitive-provider-marker'))),false);
    $cases['zero refusal']=array($answer('sensitive-provider-marker',array('message'=>array('refusal'=>'0','content'=>'sensitive-provider-marker'))),false);
    foreach (array('length','content_filter','tool_calls','function_call','unknown',null,5,array()) as $finish) { $cases['finish '.wp_json_encode($finish)]=array($answer('sensitive-provider-marker',array('finish_reason'=>$finish)),false); }
    foreach ($cases as $name=>$case) {
        $result=$complete(array('body'=>$case[0],'status'=>200));
        $assert($case[1]?is_string($result):is_wp_error($result),'Provider contract: '.$name);
        if (!$case[1]) { $assert($result->get_error_data()['status']===503 && !str_contains($result->get_error_message(),'sensitive-provider-marker'),'Controlled redacted failure: '.$name); }
    }
    foreach (array(0,201,204,301,400,401,403,408,429,500,502,503) as $status) {
        $result=$complete(array('status'=>$status,'body'=>'sensitive-provider-marker'));
        $assert(is_wp_error($result) && $result->get_error_data()['status']===503,'Controlled HTTP status '.$status);
    }
    $result=$complete(new WP_Error('http_request_failed','sensitive-provider-marker'));
    $assert(is_wp_error($result) && !str_contains($result->get_error_message(),'sensitive-provider-marker'),'Transport timeout redacted without retry');

    if ($rpc===null) { return; } // Explicit provider-only profile: MCP remains deferred.
    $request=function($method,$params=null,$id=0) { $body=array('jsonrpc'=>'2.0','id'=>$id,'method'=>$method); if ($params!==null) { $body['params']=$params; } return wp_json_encode($body); };
    $init=(object)array('protocolVersion'=>'2025-06-18','capabilities'=>new stdClass(),'clientInfo'=>(object)array('name'=>'isolated fixture','version'=>'1'));
    foreach (array(0,7,'fixture-id') as $id) {
        $res=$rpc($request('initialize',$init,$id));
        $assert($res['data']['id']===$id && $res['data']['result']['protocolVersion']==='2025-06-18','MCP preserves request ID '.wp_json_encode($id));
        $assert(array_keys($res['data']['result']['capabilities'])===array('tools'),'MCP advertises only tools capability');
    }
    $init->protocolVersion='2099-01-01';
    $assert($rpc($request('initialize',$init))['data']['result']['protocolVersion']==='2025-06-18','Unsupported requested version negotiates the supported version');
    $invalid=array('null','[]','[{}]','{}','"text"','{"jsonrpc":"1.0","method":"ping","id":1}','{"jsonrpc":"2.0","method":2,"id":1}','{"jsonrpc":"2.0","method":"ping","id":null}','{"jsonrpc":"2.0","method":"ping","id":true}','{"jsonrpc":"2.0","method":"ping","id":1.5}','{"jsonrpc":"2.0","method":"ping","id":[]}','{"jsonrpc":"2.0","method":"ping","params":[],"id":1}','{"jsonrpc":"2.0","id":1,"result":{}}');
    foreach ($invalid as $raw) { $res=$rpc($raw); $assert($res['status']===400 && $res['data']['error']['code']===-32600 && !isset($res['data']['result']),'Malformed MCP envelope rejected: '.$raw); }
    $assert($rpc('{')['data']['error']['code']===-32700,'MCP parse error');
    $assert($rpc(str_repeat(' ',16385))['status']===413,'MCP request body bounded');
    foreach (array('{"jsonrpc":"2.0","method":"notifications/initialized"}','{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":8}}','{"jsonrpc":"2.0","method":"tools/call","params":{"name":"delete_order"}}') as $raw) {
        $res=$rpc($raw); $assert($res['status']===202 && $res['data']===null && $res['headers']['Cache-Control']==='no-store','Notification acknowledged with no result');
    }
    $assert($rpc($request('notifications/initialized'))['data']['error']['code']===-32600,'Notification carrying an ID rejected');
    $assert($rpc($request('initialize',new stdClass()))['data']['error']['code']===-32602,'Missing init capabilities/client information rejected');
    $assert($rpc($request('ping'))['data']['result'] instanceof stdClass,'Ping result is empty object');
    $tools=$rpc($request('tools/list'));
    $assert(count($tools['data']['result']['tools'])===2 && $tools['headers']['Cache-Control']==='no-store','Only two read-only tools, responses uncached');
    $assert($rpc($request('tools/list',(object)array('cursor'=>'unknown')))['data']['error']['code']===-32602,'Unknown list cursor cannot pretend to paginate');
    foreach (array('orders','downloads','refund','resources/list','prompts/list','unknown') as $method) { $assert($rpc($request($method))['data']['error']['code']===-32601,'Unadvertised method rejected: '.$method); }
    $args=function($query) { return (object)array('name'=>'search_knowledge','arguments'=>(object)array('query'=>$query)); };
    foreach (array(null,2,array(),str_repeat('a',201),str_repeat('न',201)) as $query) { $assert($rpc($request('tools/call',$args($query)))['data']['error']['code']===-32602,'Invalid tool query type/length rejected'); }
    $extra=$args('policy'); $extra->arguments->order_id=42;
    $assert(($rpc($request('tools/call',$extra))['data']['error']['code']??null)===-32602,'Tool input honors additionalProperties false');
    $unknown=$args('policy'); $unknown->name='delete_order';
    $assert($rpc($request('tools/call',$unknown))['data']['error']['code']===-32602,'No private or mutation tool');
    foreach (array('',str_repeat('a',200),str_repeat('न',200)) as $query) {
        $res=$rpc($request('tools/call',$args($query)));
        $assert(isset($res['data']['result']['content'][0]['text']) && $res['data']['result']['isError']===false,'Tool text result and Unicode character boundary');
    }
}
