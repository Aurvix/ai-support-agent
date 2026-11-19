<?php
// Structured synthetic tickets only; shared between WP doubles and real disposable WordPress.
function inbox_contract($assert,$identity) {
    $identity(1,true); $assert(AISA_Inbox::retention()===7,'Inbox retention defaults to seven days');
    $beforeSettings=get_option('aisa_settings');
    foreach (array('wrong',array(),null) as $topic) { $assert(is_wp_error(AISA_Inbox::apply('create',array('topic'=>$topic))),'Invalid local topic rejected'); }
    $first=AISA_Inbox::apply('create',array('topic'=>'orders','message'=>'SECRET_CANARY download_file=private','customer_id'=>3,'post_author'=>3,'status'=>'resolved'));
    $assert(!is_wp_error($first) && $first['id']>0,'Administrator creates a structured local review ticket');
    $id=$first['id']; $post=get_post($id); $s=json_decode($post->post_content,true);
    $assert($post->post_type==='aisa_ticket' && $post->post_status==='private' && (int)$post->post_author===1,'Ticket type, private status and author fixed by server');
    $assert($s['status']==='open' && $s['revision']===1 && $s['expires']-$s['created']===7*DAY_IN_SECONDS,'New ticket starts open with absolute deadline');
    $assert(!str_contains($post->post_content,'SECRET_CANARY') && !str_contains($post->post_content,'download_file') && !str_contains($post->post_content,'customer_id'),'Untrusted raw customer/message/owner fields never persist');
    $assert(count(AISA_Inbox::visible())===1 && wp_next_scheduled(AISA_Inbox::HOOK),'Owned ticket visible and bounded cleanup scheduled');
    $identity(3,true); $assert(AISA_Inbox::visible()===array(),'Another administrator cannot list owner tickets');
    $denied=AISA_Inbox::apply('status',array('id'=>(string)$id,'revision'=>'1','status'=>'resolved'));
    $assert(is_wp_error($denied) && $denied->get_error_data()['status']===403 && get_post($id)->post_content===$post->post_content,'Another administrator cannot mutate owner ticket');
    $assert(!is_wp_error(AISA_Inbox::apply('purge',array('confirm'=>'delete_mine'))) && get_post($id)!==null,'Another administrator cannot purge owner ticket');
    AISA_Inbox::apply('retention',array('days'=>'14'));
    $assert(AISA_Inbox::retention()===14,'Retention preference belongs to current administrator');
    $identity(2,false); $assert(AISA_Inbox::visible()===array() && is_wp_error(AISA_Inbox::apply('create',array('topic'=>'general'))),'Customer capability denied for local inbox');
    $identity(0,false); $assert(is_wp_error(AISA_Inbox::apply('create',array('topic'=>'general'))),'Anonymous principal denied');
    $identity(1,true); $assert(AISA_Inbox::retention()===7,'Other owner retention does not affect A');
    foreach (array('0','-1','1x',array()) as $bad) { $assert(is_wp_error(AISA_Inbox::apply('status',array('id'=>$bad,'revision'=>'1','status'=>'resolved'))),'Malformed ticket reference rejected'); }
    foreach (array('refund','delete',array(),null) as $status) { $assert(is_wp_error(AISA_Inbox::apply('status',array('id'=>(string)$id,'revision'=>'1','status'=>$status))),'No arbitrary ticket action/status'); }
    $expires=$s['expires'];
    for ($i=0;$i<8;$i++) {
        $current=json_decode(get_post($id)->post_content,true);
        $result=AISA_Inbox::apply('status',array('id'=>(string)$id,'revision'=>(string)$current['revision'],'status'=>array('in_review','resolved','open')[$i%3]));
        if (is_wp_error($result)) { throw new RuntimeException('Synthetic status update failed'); }
    }
    $current=json_decode(get_post($id)->post_content,true);
    $assert(count($current['events'])===5 && $current['revision']===9 && $current['expires']===$expires,'Status history/revision bounded, activity never extends expiry');
    $assert(is_wp_error(AISA_Inbox::apply('status',array('id'=>(string)$id,'revision'=>'1','status'=>'resolved'))),'Stale review form rejected');
    $assert(is_wp_error(AISA_Inbox::apply('purge',array())) && get_post($id)!==null,'Purge requires explicit confirmation');
    AISA_Inbox::apply('retention',array('days'=>'30'));
    $assert(AISA_Inbox::retention()===30 && json_decode(get_post($id)->post_content,true)['expires']===$expires,'Retention change affects future tickets only');
    foreach (array('0','31',array(),null) as $days) { $assert(is_wp_error(AISA_Inbox::apply('retention',array('days'=>$days))),'Retention cannot exceed allowed bounds'); }
    $current['created']=time()-8*DAY_IN_SECONDS; $current['updated']=$current['created']; $current['expires']=time()-1; $current['events']=array(array('at'=>$current['created'],'status'=>$current['status']));
    wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>wp_json_encode($current))));
    $assert(AISA_Inbox::visible()===array(),'Expired ticket hidden before cron runs');
    $expired=AISA_Inbox::apply('status',array('id'=>(string)$id,'revision'=>(string)$current['revision'],'status'=>'open'));
    $assert(is_wp_error($expired) && $expired->get_error_data()['status']===410,'Expired ticket cannot be revived by status update');
    AISA_Inbox::cleanup(); $assert(get_post($id)===null,'Cleanup physically deletes expired ticket');
    $foreign=wp_insert_post(array('post_type'=>'aisa_knowledge','post_status'=>'private','post_title'=>'Synthetic unrelated sentinel','post_author'=>1));
    $assert(is_wp_error(AISA_Inbox::apply('status',array('id'=>(string)$foreign,'revision'=>'1','status'=>'open'))),'Ticket mutation cannot target another post type');
    $bad=AISA_Inbox::apply('create',array('topic'=>'knowledge')); $badId=$bad['id'];
    $validBad=json_decode(get_post($badId)->post_content,true);
    foreach (array('extra','events','size','json','status') as $malformed) {
        $broken=$validBad;
        if ($malformed==='extra') { $broken['message']='SECRET_CANARY'; }
        elseif ($malformed==='events') { $broken['events']=array_fill(0,6,$broken['events'][0]); }
        elseif ($malformed==='size') { $broken['topic']=str_repeat('x',2100); }
        elseif ($malformed==='status') { $broken['status']=array('open'); }
        $content=$malformed==='json'?'broken json':wp_json_encode($broken);
        wp_update_post(wp_slash(array('ID'=>$badId,'post_content'=>$content)));
        $assert(AISA_Inbox::visible()===array() && is_wp_error(AISA_Inbox::apply('status',array('id'=>(string)$badId,'revision'=>'1','status'=>'open'))),'Malformed/oversized local ticket fails closed: '.$malformed);
    }
    wp_update_post(wp_slash(array('ID'=>$badId,'post_content'=>wp_json_encode($validBad))));
    $badState=json_decode(get_post($badId)->post_content,true); $badState['site']=get_current_blog_id()+1;
    wp_update_post(wp_slash(array('ID'=>$badId,'post_content'=>wp_json_encode($badState))));
    $assert(AISA_Inbox::visible()===array() && is_wp_error(AISA_Inbox::apply('status',array('id'=>(string)$badId,'revision'=>'1','status'=>'resolved'))),'Wrong-site payload fails closed');
    AISA_Inbox::cleanup();
    for ($i=0;$i<AISA_Inbox::OWNER_LIMIT;$i++) { $result=AISA_Inbox::apply('create',array('topic'=>'general')); if (is_wp_error($result)) { throw new RuntimeException('Could not fill synthetic owner quota'); } }
    $assert(count(AISA_Inbox::visible())===25 && is_wp_error(AISA_Inbox::apply('create',array('topic'=>'general'))),'Owner ticket count capped at 25');
    // Seed the rest with explicit synthetic storage fixtures; customer cannot create through the workflow.
    $seed=json_decode(get_post($result['id'])->post_content,true);
    for ($i=25;$i<AISA_Inbox::SITE_LIMIT;$i++) { wp_insert_post(wp_slash(array('post_type'=>'aisa_ticket','post_status'=>'private','post_author'=>2,'post_title'=>'Synthetic site quota fixture','post_content'=>wp_json_encode($seed),'meta_input'=>array('aisa_ticket_owner'=>2)))); }
    $identity(3,true); $assert(is_wp_error(AISA_Inbox::apply('create',array('topic'=>'general'))),'Site ticket count capped at 100 across owners');
    $identity(1,true); AISA_Inbox::apply('purge',array('confirm'=>'delete_mine'));
    $assert(AISA_Inbox::visible()===array() && count(get_posts(array('post_type'=>'aisa_ticket','post_status'=>'any','posts_per_page'=>101)))===75,'Owner purge preserves other owners');
    $assert(get_post($foreign)!==null && get_option('aisa_settings')===$beforeSettings,'Inbox does not alter unrelated content or authentication/provider settings');
    wp_delete_post($foreign,true);
    foreach (get_posts(array('post_type'=>'aisa_ticket','post_status'=>'any','posts_per_page'=>101)) as $p) { wp_delete_post($p->ID,true); }
    delete_option(AISA_Inbox::RETENTION);
}
