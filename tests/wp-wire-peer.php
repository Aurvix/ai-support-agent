<?php
// Test-only provider interception. No user/auth overrides and no network adapter.
add_filter('pre_http_request',function($pre,$args,$url) {
    if ($url!=='https://wire.fixture.invalid/chat') { return $pre; }
    update_option('aisa_wire_calls',(int)get_option('aisa_wire_calls')+1);
    $case=getenv('AISA_WIRE_CASE');
    if ($case==='timeout') { return new WP_Error('timeout','synthetic-wire-upstream-private-marker'); }
    if ($case==='revoke') { update_post_meta((int)get_option('aisa_wire_record'),'aisa_approved_hash',''); }
    $status=in_array($case,array('401','429','503'),true)?(int)$case:200;
    $body=wp_json_encode(array('choices'=>array(array('finish_reason'=>$case==='truncated'?'length':'stop','message'=>array('role'=>'assistant','content'=>'Synthetic serialized answer.')))));
    if ($case==='bad-json') { $body='{synthetic-wire-upstream-private-marker'; }
    if ($status!==200) { $body='synthetic-wire-upstream-private-marker'; }
    return array('headers'=>array(),'response'=>array('code'=>$status,'message'=>'Synthetic'),'body'=>$body,'cookies'=>array());
},PHP_INT_MAX,3);
