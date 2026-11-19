<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
// Saved customer chat text is removed on uninstall; configuration/knowledge remain opt-in.
(static function() {
    $statuses=array_merge(array('any'),array_values(get_post_stati()));
    do {
        $ids=get_posts(array('post_type'=>'aisa_conversation','post_status'=>$statuses,'posts_per_page'=>100,'fields'=>'ids','suppress_filters'=>true));
        foreach($ids as$id){
            // Unreviewed excerpts are chat copies. Deliberately approved knowledge is retained.
            do{$drafts=get_posts(array('post_type'=>'aisa_knowledge','post_status'=>$statuses,'posts_per_page'=>100,'suppress_filters'=>true,'meta_query'=>array('relation'=>'AND',array('key'=>'aisa_conversation_id','value'=>$id),array('relation'=>'OR',array('key'=>'aisa_approved_hash','compare'=>'NOT EXISTS'),array('key'=>'aisa_approved_hash','value'=>'')))));foreach($drafts as$draft){
                $origin=get_post_meta($draft->ID,'aisa_origin',true);
                if(!is_array($origin)||($origin['kind']??'')!=='conversation'||(int)($origin['conversation']??0)!==(int)$id)wp_die('Chat excerpt ownership could not be verified. Retry after review.','',array('response'=>503));
                if(!wp_delete_post($draft->ID,true)||get_post($draft->ID)!==null)wp_die('Chat excerpt cleanup is incomplete. Retry plugin removal.','',array('response'=>503));
            }}while($drafts);
            if(!wp_delete_post($id,true)||get_post($id)!==null)wp_die('Chat cleanup is incomplete. Retry plugin removal.','',array('response'=>503));
        }
    }while($ids);
    do{$users=get_users(array('fields'=>'ids','number'=>100,'meta_key'=>'aisa_staff_keys_'.get_current_blog_id(),'meta_compare'=>'EXISTS'));foreach($users as$uid){foreach((array)get_user_meta($uid,'aisa_staff_keys_'.get_current_blog_id(),true)as$key)if(is_string($key)&&preg_match('/^aisa_staff_[a-f0-9]{64}$/D',$key)){delete_transient($key);delete_transient($key.'_proposal');delete_transient($key.'_undo');}delete_user_meta($uid,'aisa_staff_keys_'.get_current_blog_id());if(get_user_meta($uid,'aisa_staff_keys_'.get_current_blog_id(),true)!=='')wp_die('Administrator chat cleanup is incomplete.','',array('response'=>503));}}while($users);
    foreach(array('aisa_conversation_lock','aisa_conversation_review_lock','aisa_conversation_review_budget')as$key)delete_option($key);
    wp_clear_scheduled_hook('aisa_conversation_cleanup');wp_clear_scheduled_hook('aisa_conversation_review');
})();
// Other settings, approved knowledge and structured tickets are retained unless opted in.
if (!defined('AISA_DELETE_DATA') || AISA_DELETE_DATA !== true) { return; }
(static function() {
    // Include excluded and unregistered statuses. "any" alone excludes trash/auto-drafts.
    $statuses=array_merge(array('any'),array_values(get_post_stati()));
    foreach (array('aisa_knowledge','aisa_draft','aisa_audit','aisa_ticket','aisa_request','aisa_conversation') as $type) {
        do {
            $ids=get_posts(array('post_type'=>$type,'post_status'=>$statuses,'posts_per_page'=>100,'fields'=>'ids','suppress_filters'=>true));
            foreach ($ids as $id) {
                if (!wp_delete_post($id,true) || get_post($id)!==null) { wp_die('AI Support data removal is incomplete. Cleanup stopped; retained settings allow a deliberate retry.','',array('response'=>503)); }
            }
        } while ($ids);
    }
    // Remove bounded administrator AI sessions from both DB and external transient caches.
    do{$staffUsers=get_users(array('fields'=>'ids','number'=>100,'meta_key'=>'aisa_staff_keys_'.get_current_blog_id(),'meta_compare'=>'EXISTS'));foreach($staffUsers as$uid){foreach((array)get_user_meta($uid,'aisa_staff_keys_'.get_current_blog_id(),true)as$key)if(is_string($key)&&preg_match('/^aisa_staff_[a-f0-9]{64}$/D',$key)){delete_transient($key);delete_transient($key.'_proposal');delete_transient($key.'_undo');}delete_user_meta($uid,'aisa_staff_keys_'.get_current_blog_id());if(get_user_meta($uid,'aisa_staff_keys_'.get_current_blog_id(),true)!=='')wp_die('Administrator session cleanup is incomplete.','',array('response'=>503));}}while($staffUsers);
    // Source holds are plugin-owned metadata on otherwise untouched public content.
    foreach(array('_aisa_public_sync_hold','_aisa_public_sync_allowed')as$key)delete_metadata('post',0,$key,'',true);
    global $wpdb;if((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_aisa_public_sync_hold','_aisa_public_sync_allowed')")>0)wp_die('Source hold cleanup is incomplete. Settings retained for retry.','',array('response'=>503));
    foreach (array('aisa_settings','aisa_sync_state','aisa_sync_lock','aisa_index_page','aisa_memory_epoch','aisa_inbox_retention','aisa_inbox_lock','aisa_request_rates','aisa_ticket_owners_ready','aisa_conversation_config','aisa_conversation_lock','aisa_conversation_review_budget','aisa_conversation_review_lock') as $key) { delete_option($key); }
    delete_transient('aisa_model_observation'); delete_transient('aisa_model_probe_until');
    wp_clear_scheduled_hook('aisa_index_batch'); wp_clear_scheduled_hook('aisa_public_daily'); wp_clear_scheduled_hook('aisa_public_batch'); wp_clear_scheduled_hook('aisa_inbox_cleanup'); wp_clear_scheduled_hook('aisa_conversation_cleanup'); wp_clear_scheduled_hook('aisa_conversation_review');
})();
