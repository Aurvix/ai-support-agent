<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
// Retain data unless the site owner explicitly opts into deletion.
if (!defined('AISA_DELETE_DATA') || AISA_DELETE_DATA !== true) { return; }
foreach (array('aisa_knowledge','aisa_draft','aisa_audit','aisa_ticket','aisa_request') as $type) {
    do {
        $ids=get_posts(array('post_type'=>$type,'post_status'=>'any','posts_per_page'=>100,'fields'=>'ids'));
        foreach ($ids as $id) { wp_delete_post($id,true); }
    } while ($ids);
}
delete_option('aisa_settings');
delete_option('aisa_index_page');
delete_option('aisa_memory_epoch');
delete_option('aisa_inbox_retention');
delete_option('aisa_inbox_lock');
delete_option('aisa_request_rates');
wp_clear_scheduled_hook('aisa_index_batch');
wp_clear_scheduled_hook('aisa_inbox_cleanup');
