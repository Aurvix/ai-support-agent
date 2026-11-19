<?php
require __DIR__.'/wp-load.php'; require_once ABSPATH.'wp-admin/includes/plugin.php';
$checks=0;
function lifecycle_check($ok) { global $checks; $checks++; if (!$ok) { throw new RuntimeException('Disposable inbox lifecycle check failed'); } }
wp_set_current_user(1); AISA_Inbox::apply('retention',array('days'=>'14'));
$ticket=AISA_Inbox::apply('create',array('topic'=>'general')); lifecycle_check(!is_wp_error($ticket));
lifecycle_check((bool)wp_next_scheduled(AISA_Inbox::HOOK));
$accountId=0;
if (class_exists('AISA_Handoff')) {
    $settings=AISA_Agent::settings(); $settings['customer_tickets']=1; update_option('aisa_settings',$settings); wp_set_current_user(2);
    $request=new WP_REST_Request('POST','/aisa/v1/tickets/submit'); $request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $request->set_header('Content-Type','application/json');
    $request->set_body(wp_json_encode(array('scope'=>AISA_Memory::scope(),'topic'=>'general','confirm'=>true,'request_id'=>str_repeat('e',32))));
    $result=AISA_Handoff::submit($request); lifecycle_check(!is_wp_error($result)); $accountId=$result->get_data()['ticket']['id']; wp_set_current_user(1);
}
deactivate_plugins('ai-support-agent/ai-support-agent.php',false);
lifecycle_check(!wp_next_scheduled(AISA_Inbox::HOOK) && get_post($ticket['id'])!==null && AISA_Inbox::retention()===14);
if ($accountId) { lifecycle_check(get_post($accountId)!==null && get_option(AISA_Handoff::RATES)!==false); }
define('WP_UNINSTALL_PLUGIN','ai-support-agent/ai-support-agent.php');
require ABSPATH.'wp-content/plugins/ai-support-agent/uninstall.php';
lifecycle_check(get_post($ticket['id'])!==null && AISA_Inbox::retention()===14);
if ($accountId) { lifecycle_check(get_post($accountId)!==null); }
define('AISA_DELETE_DATA',true);
require ABSPATH.'wp-content/plugins/ai-support-agent/uninstall.php';
lifecycle_check(get_post($ticket['id'])===null && get_option(AISA_Inbox::RETENTION)===false && get_option(AISA_Inbox::LOCK)===false);
lifecycle_check(!wp_next_scheduled(AISA_Inbox::HOOK) && get_option('aisa_settings')===false);
if ($accountId) { lifecycle_check(get_post($accountId)===null && get_option(AISA_Handoff::RATES)===false); }
echo wp_json_encode(array('status'=>'PASS','checks'=>$checks,'scope'=>'disposable synthetic database only','external_sends'=>0));
