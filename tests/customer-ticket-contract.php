<?php
// Shared synthetic account/reviewer contract: actual HTTP auth filters are supplied by the real-WP adapter.
function customer_ticket_contract($assert,$identity,$call) {
    $assert(AISA_Agent::defaults()['customer_tickets']===0,'Account ticket intake defaults off');
    $settings=AISA_Agent::settings(); $settings['customer_tickets']=0; update_option('aisa_settings',$settings);
    $identity(2,false); $scope=AISA_Memory::scope();
    $body=array('scope'=>$scope,'topic'=>'orders','confirm'=>true,'request_id'=>str_repeat('a',32));
    $assert($call('submit',$body)['status']===503 && $call('status',array('scope'=>$scope))['status']===503,'Disabled intake denies submission and status');
    $settings['customer_tickets']=1; update_option('aisa_settings',$settings);
    $beforeRates=get_option(AISA_Handoff::RATES); $beforeSchedule=wp_next_scheduled(AISA_Inbox::HOOK);
    $empty=$call('status',array('scope'=>$scope));
    $assert($empty['status']===200 && $empty['data']['tickets']===array() && get_option(AISA_Handoff::RATES)===$beforeRates && wp_next_scheduled(AISA_Inbox::HOOK)===$beforeSchedule,'Own status is read-only and initially empty');
    foreach (array(false,'true',1,null,array()) as $confirm) { $bad=$body; $bad['confirm']=$confirm; $assert($call('submit',$bad)['status']===400,'Submission requires literal explicit confirmation'); }
    foreach (array('refund','delete',array(),null) as $topic) { $bad=$body; $bad['topic']=$topic; $assert($call('submit',$bad)['status']===400,'Only declared support topics accepted'); }
    foreach (array('message','customer_id','post_author','order_id','download_url','reviewer','status') as $key) { $bad=$body; $bad[$key]='RAW_TICKET_CANARY private download_file=secret'; $assert($call('submit',$bad)['status']===400,'Unknown/private submission field rejected: '.$key); }
    foreach (array('',str_repeat('x',32),str_repeat('a',33),array(),1) as $requestId) { $bad=$body; $bad['request_id']=$requestId; $assert($call('submit',$bad)['status']===400,'Submission ID shape bounded'); }
    $first=$call('submit',$body); $assert($first['status']===200 && !$first['data']['duplicate'],'Explicit account ticket saved');
    $id=$first['data']['ticket']['id']; $post=get_post($id); $state=json_decode($post->post_content,true); $expires=$state['expires'];
    $assert($post->post_type==='aisa_request' && $post->post_status==='private' && (int)$post->post_author===2 && $state['reviewer']===0 && $state['status']==='open','Server sets owner, private type, unassigned reviewer and open state');
    $assert(array_keys($state)===array('site','topic','status','created','expires','updated','revision','reviewer','request_hash','events') && !str_contains($post->post_content,'RAW_TICKET_CANARY') && !str_contains($post->post_content,$body['request_id']),'Stored schema excludes raw payload and raw request ID');
    $rates=get_option(AISA_Handoff::RATES); $again=$call('submit',$body);
    $assert($again['status']===200 && $again['data']['duplicate'] && $again['data']['ticket']['id']===$id && get_option(AISA_Handoff::RATES)===$rates,'Same account/session submission ID deduplicates without quota charge');
    $bad=$body; $bad['topic']='general'; $assert($call('submit',$bad)['status']===409,'Reusing request ID for another topic rejected');
    $own=$call('status',array('scope'=>$scope));
    $assert(count($own['data']['tickets'])===1 && array_keys($own['data']['tickets'][0])===array('id','topic','status','created','updated','expires','review_availability'),'Customer receives only minimal own ticket summary');
    $assert($own['headers']['Cache-Control']==='private, no-store' && $first['headers']['Cache-Control']==='private, no-store','Private ticket responses are not cached');
    $assert($call('status',array('scope'=>$scope),'missing')['status']===401 && $call('status',array('scope'=>$scope),'invalid')['status']===403 && $call('submit',$body,'expired')['status']===403,'Missing/invalid/expired REST nonce denied');
    $identity(0,false); $assert($call('status',array('scope'=>$scope))['status']===401 && $call('submit',$body)['status']===401,'Anonymous ticket access denied');
    $identity(3,false); $assert($call('status',array('scope'=>$scope))['status']===409 && $call('submit',$body)['status']===409,'B cannot reuse A identity scope');
    $scopeB=AISA_Memory::scope(); $assert($call('status',array('scope'=>$scopeB))['data']['tickets']===array(),'B cannot list A tickets');
    $assert($call('status',array('scope'=>$scopeB,'id'=>$id))['status']===400 && $call('status',array('scope'=>$scopeB,'customer_id'=>2))['status']===400,'Own status accepts no ID/owner override');
    $other=$body; $other['scope']=$scopeB; $second=$call('submit',$other);
    $assert($second['status']===200 && $second['data']['ticket']['id']!==$id && (int)get_post($second['data']['ticket']['id'])->post_author===3,'Same request ID across accounts remains isolated');
    $identity(2,false); $epoch=(int)get_option('aisa_memory_epoch',0); update_option('aisa_memory_epoch',$epoch+1);
    $assert($call('status',array('scope'=>$scope))['status']===409,'Rotated support scope rejects stale page');
    $scope=AISA_Memory::scope(); $body['scope']=$scope;
    $assert($call('status',array('scope'=>$scope))['data']['tickets'][0]['id']===$id,'Fresh scope retains only the same account history');
    $assert(is_wp_error(AISA_Handoff::review('claim',array('id'=>(string)$id,'revision'=>'1'))),'Customer cannot invoke administrator review');
    $identity(1,true); $assert(count(AISA_Handoff::reviews())===2,'Administrator sees unassigned structured requests');
    $assert(is_wp_error(AISA_Handoff::review('status',array('id'=>(string)$id,'revision'=>'1','status'=>'resolved'))),'Unassigned ticket must be explicitly claimed');
    $assert(!is_wp_error(AISA_Handoff::review('claim',array('id'=>(string)$id,'revision'=>'1'))),'Administrator explicitly claims a request');
    $claimed=json_decode(get_post($id)->post_content,true);
    $assert($claimed['reviewer']===1 && $claimed['revision']===2 && $claimed['status']==='in_review' && $claimed['expires']===$expires,'Claim sets reviewer/status without extending expiry');
    $identity(3,true);
    $assert(count(AISA_Handoff::reviews())===1 && AISA_Handoff::reviews()[0]['id']!==$id,'Other administrator cannot list claimed request');
    foreach (array('claim','status','delete') as $op) { $denied=AISA_Handoff::review($op,array('id'=>(string)$id,'revision'=>'2','status'=>'resolved','confirm'=>'delete_ticket')); $assert(is_wp_error($denied) && $denied->get_error_data()['status']===403,'Other reviewer cannot '.$op.' assigned request'); }
    $identity(1,true); $assert(is_wp_error(AISA_Handoff::review('status',array('id'=>(string)$id,'revision'=>'1','status'=>'resolved'))),'Stale reviewer form rejected');
    foreach (array('refund',array(),null) as $status) { $assert(is_wp_error(AISA_Handoff::review('status',array('id'=>(string)$id,'revision'=>'2','status'=>$status))),'Review status cannot trigger arbitrary actions'); }
    for ($i=0;$i<8;$i++) { $s=json_decode(get_post($id)->post_content,true); $result=AISA_Handoff::review('status',array('id'=>(string)$id,'revision'=>(string)$s['revision'],'status'=>array('resolved','open','in_review')[$i%3])); if (is_wp_error($result)) { throw new RuntimeException('Synthetic customer review failed'); } }
    $valid=json_decode(get_post($id)->post_content,true);
    $assert(count($valid['events'])===5 && $valid['expires']===$expires,'Review history bounded and expiry absolute');
    $identity(2,false); $assert($call('status',array('scope'=>$scope))['data']['tickets'][0]['status']===$valid['status'],'Customer can read current reviewed status');
    foreach (array('site','extra','expires','reviewer','events','json') as $case) {
        $bad=$valid;
        if ($case==='site') { $bad['site']++; } elseif ($case==='extra') { $bad['transcript']='RAW_TICKET_CANARY'; }
        elseif ($case==='expires') { $bad['expires']++; } elseif ($case==='reviewer') { $bad['reviewer']=-1; } elseif ($case==='events') { $bad['events']=array_fill(0,6,$valid['events'][0]); }
        wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>$case==='json'?'bad-json':wp_json_encode($bad))));
        $assert($call('status',array('scope'=>$scope))['data']['tickets']===array(),'Malformed/wrong-site request hidden: '.$case);
    }
    $valid['created']=time()-8*DAY_IN_SECONDS; $valid['expires']=$valid['created']+AISA_Handoff::TTL; $valid['updated']=$valid['created']; $valid['events']=array(array('at'=>$valid['created'],'status'=>$valid['status']));
    wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>wp_json_encode($valid))));
    $assert($call('status',array('scope'=>$scope))['data']['tickets']===array(),'Expired account request hidden before cleanup');
    AISA_Handoff::cleanup(); $assert(get_post($id)===null,'Cleanup deletes expired customer metadata');
    for ($i=0;$i<3;$i++) { $new=$body; $new['request_id']=str_repeat(dechex($i+10),32); $created=$call('submit',$new); if ($created['status']!==200) { throw new RuntimeException('Synthetic owner limit setup failed'); } }
    $assert($call('submit',array_merge($body,array('request_id'=>str_repeat('f',32))))['status']===429 && count($call('status',array('scope'=>$scope))['data']['tickets'])===3,'Retained customer ticket count capped at three');
    $assert($call('submit',$new)['data']['duplicate'],'Same form retry works at retained-ticket limit');
    $identity(1,true);
    foreach (get_posts(array('post_type'=>'aisa_request','post_status'=>'private','author'=>2,'posts_per_page'=>101)) as $p) {
        $s=json_decode($p->post_content,true); AISA_Handoff::review('claim',array('id'=>(string)$p->ID,'revision'=>(string)$s['revision'])); $s=json_decode(get_post($p->ID)->post_content,true);
        $assert(is_wp_error(AISA_Handoff::review('delete',array('id'=>(string)$p->ID,'revision'=>(string)$s['revision']))),'Review deletion requires confirmation');
        AISA_Handoff::review('delete',array('id'=>(string)$p->ID,'revision'=>(string)$s['revision'],'confirm'=>'delete_ticket'));
    }
    $identity(2,false); $last=$call('submit',array_merge($body,array('request_id'=>str_repeat('e',32))));
    $assert($last['status']===200,'Fifth sliding-day submission allowed after retained metadata is deleted');
    wp_delete_post($last['data']['ticket']['id'],true);
    $limited=$call('submit',array_merge($body,array('request_id'=>str_repeat('f',32))));
    $assert($limited['status']===429 && $limited['data']['code']==='ticket_rate','Sliding-day quota survives deletion');
    delete_option(AISA_Handoff::RATES);
    $seed=json_decode(get_post($second['data']['ticket']['id'])->post_content,true);
    for ($i=count(get_posts(array('post_type'=>'aisa_request','post_status'=>'any','posts_per_page'=>101)));$i<AISA_Handoff::LIMIT;$i++) { wp_insert_post(wp_slash(array('post_type'=>'aisa_request','post_status'=>'private','post_author'=>3,'post_title'=>'Synthetic site quota fixture','post_content'=>wp_json_encode($seed),'meta_input'=>array('aisa_ticket_owner'=>3)))); }
    $full=$call('submit',$body); $assert($full['status']===429 && $full['data']['code']==='ticket_limit','Site customer queue capped at 100');
    foreach (get_posts(array('post_type'=>'aisa_request','post_status'=>'any','posts_per_page'=>101)) as $p) { wp_delete_post($p->ID,true); }
    add_option(AISA_Inbox::LOCK,time()-3600);
    $busy=$call('submit',$body); $assert($busy['status']===409 && $busy['data']['code']==='ticket_busy' && get_option(AISA_Inbox::LOCK)!==false,'Account request respects shared fail-closed lock');
    $assert($call('status',array('scope'=>$scope))['status']===200,'Own status remains readable while writes are busy'); delete_option(AISA_Inbox::LOCK);
    update_option(AISA_Handoff::RATES,array('broken'=>'not-times')); $assert($call('submit',$body)['status']===503,'Corrupt submission counters fail closed'); delete_option(AISA_Handoff::RATES);
    update_option('aisa_memory_epoch',$epoch);
}
