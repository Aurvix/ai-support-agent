<?php
require __DIR__.'/wp-load.php'; require_once ABSPATH.'wp-admin/includes/plugin.php';
$checks=0; $phase=getenv('AISA_QUALITY_PHASE');
function ucheck($ok,$label) { global $checks; $checks++; if (!$ok) { throw new RuntimeException('Inactive uninstall: '.$label); } }
if ($phase==='activate') {
    $result=activate_plugin('ai-support-agent/ai-support-agent.php','',false,false); ucheck(!is_wp_error($result),'reactivation in disposable runtime');
} elseif ($phase==='seed') {
    wp_set_current_user(1); ucheck(class_exists('AISA_Handoff') && get_option('aisa_ticket_owners_ready'),'fresh active request initializes protection');
    register_post_status('aisa_fixture_archive',array('internal'=>true,'exclude_from_search'=>true));
    $ids=array(); foreach (array('aisa_knowledge','aisa_draft','aisa_audit','aisa_ticket','aisa_request') as $type) {
        foreach (array('private','trash','auto-draft','aisa_fixture_archive') as $status) {
            $id=wp_insert_post(array('post_type'=>$type,'post_status'=>$status,'post_author'=>1,'post_title'=>'Synthetic uninstall fixture','post_content'=>'Synthetic lifecycle data only.','meta_input'=>array('aisa_ticket_owner'=>1)));
            ucheck($id>0,'seed every plugin type/status'); $ids[]=$id;
        }
    }
    $unrelated=wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_title'=>'Unrelated synthetic sentinel','post_content'=>'Do not delete this fixture.'));
    update_option('aisa_settings',array('title'=>'Retained synthetic settings')); update_option('aisa_inbox_retention',array(1=>14)); update_option('aisa_request_rates',array(2=>array(time())));
    wp_schedule_single_event(time()+DAY_IN_SECONDS,'aisa_inbox_cleanup');
    $chatIds=array();foreach(array('private','trash','auto-draft','aisa_fixture_archive')as$status)$chatIds[]=wp_insert_post(array('post_type'=>'aisa_conversation','post_status'=>$status,'post_author'=>1,'post_content'=>'Synthetic private transcript only.'));
    $excerpt=wp_insert_post(array('post_type'=>'aisa_knowledge','post_status'=>'private','post_content'=>'Synthetic unreviewed chat excerpt.','meta_input'=>array('aisa_conversation_id'=>$chatIds[0],'aisa_origin'=>array('kind'=>'conversation','conversation'=>$chatIds[0]))));
    $reviewed=wp_insert_post(array('post_type'=>'aisa_knowledge','post_status'=>'private','post_content'=>'Deliberately reviewed public FAQ.','meta_input'=>array('aisa_conversation_id'=>$chatIds[0],'aisa_origin'=>array('kind'=>'conversation','conversation'=>$chatIds[0]),'aisa_approved_hash'=>'synthetic-reviewed')));
    $foreignStaff='aisa_staff_'.str_repeat('b',64);set_transient($foreignStaff,array('synthetic'=>'other site staff chat'),600);update_user_meta(1,'aisa_staff_keys_999',array($foreignStaff));
    $staffKey='aisa_staff_'.str_repeat('a',64);set_transient($staffKey,array('synthetic'=>'private staff chat'),600);update_user_meta(1,'aisa_staff_keys_'.get_current_blog_id(),array($staffKey));
    file_put_contents('/inactive-uninstall.json',wp_json_encode(array('ids'=>$ids,'chat_ids'=>$chatIds,'excerpt'=>$excerpt,'reviewed'=>$reviewed,'staff_key'=>$staffKey,'unrelated'=>$unrelated,'roles'=>get_option('wp_user_roles'),'settings'=>get_option('aisa_settings'))));
    set_transient('aisa_model_observation',array('category'=>'synthetic'),HOUR_IN_SECONDS); set_transient('aisa_model_probe_until',time()+60,60);
    deactivate_plugins('ai-support-agent/ai-support-agent.php',false);
    ucheck(!wp_next_scheduled('aisa_inbox_cleanup'),'deactivation cancels cleanup');
} else {
    $f=json_decode(file_get_contents('/inactive-uninstall.json'),true);
    ucheck(!class_exists('AISA_Agent') && !post_type_exists('aisa_request'),'fresh request has inactive plugin and unregistered ticket type');
    define('WP_UNINSTALL_PLUGIN','ai-support-agent/ai-support-agent.php');
    if ($phase==='default') {
        class DefaultChatStopped extends RuntimeException {}
        add_filter('wp_die_handler',function(){return function($m,$t,$a){throw new DefaultChatStopped((string)($a['response']??500));};});
        $blockChat=function($pre,$post)use($f){return in_array($post->ID,$f['chat_ids'],true)?false:$pre;};add_filter('pre_delete_post',$blockChat,PHP_INT_MAX,2);
        try{require ABSPATH.'wp-content/plugins/ai-support-agent/uninstall.php';ucheck(false,'chat deletion failure must stop default uninstall');}catch(DefaultChatStopped$e){ucheck($e->getMessage()==='503','default chat cleanup failure reported');}
        remove_filter('pre_delete_post',$blockChat,PHP_INT_MAX);ucheck(get_option('aisa_settings')===$f['settings'],'failed chat cleanup preserves settings');
        require ABSPATH.'wp-content/plugins/ai-support-agent/uninstall.php';
        ucheck(count(array_filter($f['chat_ids'],'get_post'))===0&&!get_post($f['excerpt']),'default uninstall removes chat text/all statuses and unreviewed excerpts');
        ucheck((bool)get_post($f['reviewed']),'default uninstall preserves deliberately approved knowledge');
        ucheck(get_transient('aisa_staff_'.str_repeat('b',64))!==false&&get_user_meta(1,'aisa_staff_keys_999',true)!=='','uninstall preserves foreign-site staff cache/index');
        ucheck(get_transient($f['staff_key'])===false&&get_user_meta(1,'aisa_staff_keys_'.get_current_blog_id(),true)==='','default uninstall clears indexed administrator chat cache');
        ucheck(count(array_filter($f['ids'],'get_post'))===count($f['ids']) && get_option('aisa_settings')===$f['settings'],'default uninstall retains every status and settings');
        ucheck(get_transient('aisa_model_observation')!==false,'default uninstall retains bounded diagnostic until expiry');
    } else {
        define('AISA_DELETE_DATA',true);
        if ($phase==='failure' || $phase==='false-success') {
            class UninstallStopped extends RuntimeException {}
            add_filter('wp_die_handler',function(){ return function($message,$title,$args){ throw new UninstallStopped((string)($args['response']??500)); }; });
            $attempts=0; add_filter('pre_delete_post',function($pre,$post) use(&$attempts,$phase) { $attempts++; return $phase==='failure'?false:$post; },10,2);
            try { require ABSPATH.'wp-content/plugins/ai-support-agent/uninstall.php'; ucheck(false,'failed delete must stop'); }
            catch (UninstallStopped $e) { ucheck($e->getMessage()==='503' && $attempts===1,'failure stops after one attempt, no infinite loop'); }
            ucheck(get_option('aisa_settings')===$f['settings'] && count(array_filter($f['ids'],'get_post'))===count($f['ids']),'failure retains data/options for retry');
        } else {
            require ABSPATH.'wp-content/plugins/ai-support-agent/uninstall.php';
            ucheck(count(array_filter($f['ids'],'get_post'))===0,'explicit uninstall removes all core/custom/unknown statuses without loaded plugin classes');
            foreach (array('aisa_settings','aisa_index_page','aisa_memory_epoch','aisa_inbox_retention','aisa_inbox_lock','aisa_request_rates','aisa_ticket_owners_ready') as $key) { ucheck(get_option($key)===false,'plugin option removed: '.$key); }
            ucheck(get_transient('aisa_model_observation')===false && get_transient('aisa_model_probe_until')===false,'explicit uninstall removes bounded provider diagnostics/cooldown');
            ucheck(!wp_next_scheduled('aisa_index_batch') && !wp_next_scheduled('aisa_inbox_cleanup'),'plugin schedules removed');
        }
    }
    ucheck(get_post($f['unrelated'])!==null && get_option('wp_user_roles')===$f['roles'],'unrelated content/role definitions preserved');
}
echo wp_json_encode(array('status'=>'PASS','phase'=>$phase,'checks'=>$checks,'scope'=>'fresh disposable PHP request, synthetic database only'));
