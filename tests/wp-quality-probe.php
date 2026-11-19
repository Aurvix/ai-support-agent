<?php
// Observe the immutable previous release in disposable WP only. No real login or external transport.
require __DIR__.'/wp-load.php'; require_once ABSPATH.'wp-admin/includes/user.php';
function probe_user($role) {
    global $wpdb;
    $login='probe_'.wp_generate_uuid4();
    $wpdb->insert($wpdb->users,array('user_login'=>$login,'user_pass'=>'*','user_nicename'=>$login,'user_email'=>$login.'@example.test','user_registered'=>gmdate('Y-m-d H:i:s'),'display_name'=>'Synthetic lifecycle probe'));
    $id=(int)$wpdb->insert_id; if (!$id) { throw new RuntimeException('Synthetic user setup failed'); }
    (new WP_User($id))->set_role($role);
    if (wp_check_password('anything','*',$id) || WP_Session_Tokens::get_instance($id)->get_all()) { throw new RuntimeException('Synthetic user must have disabled login'); }
    return $id;
}
function probe_api($op,$uid,$params) {
    global $wp_rest_auth_cookie; wp_set_current_user($uid); $wp_rest_auth_cookie=true;
    unset($_REQUEST['_wpnonce']); $_SERVER['HTTP_X_WP_NONCE']=wp_create_nonce('wp_rest');
    $r=new WP_REST_Request('POST','/aisa/v1/tickets/'.$op); $r->set_header('Content-Type','application/json'); $r->set_header('X-WP-Nonce',$_SERVER['HTTP_X_WP_NONCE']); $r->set_body(wp_json_encode($params));
    $server=rest_get_server(); $auth=$server->check_authentication(); if (is_wp_error($auth)) { return rest_convert_error_to_response($auth); }
    return $server->dispatch($r);
}
$owner=probe_user('subscriber'); $recipient=probe_user('subscriber'); $reviewer=probe_user('administrator');
$s=AISA_Agent::settings(); $s['enabled']=1; $s['customer_tickets']=1; update_option('aisa_settings',$s);
wp_set_current_user($owner); $request=probe_api('submit',$owner,array('scope'=>AISA_Memory::scope(),'topic'=>'general','confirm'=>true,'request_id'=>str_repeat('a',32)));
if ($request->get_status()!==200) { throw new RuntimeException('Probe ticket setup failed'); }
$probeTicketId=$request->get_data()['ticket']['id']; wp_set_current_user($reviewer); AISA_Handoff::review('claim',array('id'=>(string)$probeTicketId,'revision'=>'1'));
(new WP_User($reviewer))->set_role('subscriber'); wp_set_current_user($owner);
$lost=probe_api('status',$owner,array('scope'=>AISA_Memory::scope()))->get_data()['tickets'][0];
wp_set_current_user(1); wp_delete_user($reviewer,1); wp_set_current_user($owner);
$deleted=probe_api('status',$owner,array('scope'=>AISA_Memory::scope()))->get_data()['tickets'][0];
$s['customer_tickets']=0; update_option('aisa_settings',$s);
$disabled=probe_api('status',$owner,array('scope'=>AISA_Memory::scope()))->get_status();
$context=new WP_REST_Request('POST','/aisa/v1/context'); $context->set_param('scope',AISA_Memory::scope()); $contextResult=AISA_Agent::context($context);
$s['customer_tickets']=1; update_option('aisa_settings',$s); wp_set_current_user(1);
$deleteResult=wp_delete_user($owner,$recipient); $afterDelete=get_post($probeTicketId); wp_set_current_user($recipient);
$recipientResponse=probe_api('status',$recipient,array('scope'=>AISA_Memory::scope())); $recipientView=$recipientResponse->get_data()['tickets'];
$ownerRates=get_option(AISA_Handoff::RATES,array());
$recipientCount=count($recipientView); $transferState=(new ReflectionMethod('AISA_Handoff','state'))->invoke(null,get_post($probeTicketId));
$authorQuery=get_posts(array('post_type'=>'aisa_request','post_status'=>'any','author'=>$recipient,'posts_per_page'=>101));
wp_update_post(array('ID'=>$probeTicketId,'post_status'=>'trash'));
$beforeUninstall=get_post($probeTicketId);
$anyTrash=get_posts(array('post_type'=>'aisa_request','post_status'=>'any','posts_per_page'=>101));
define('WP_UNINSTALL_PLUGIN','ai-support-agent/ai-support-agent.php'); define('AISA_DELETE_DATA',true);
require ABSPATH.'wp-content/plugins/ai-support-agent/uninstall.php';
echo wp_json_encode(array('status'=>'OBSERVED','plugin_version'=>AISA_VERSION,'findings'=>array(
    'reassigned_recipient_can_read_previous_owner_ticket'=>count($recipientView)===1 && $recipientView[0]['id']===$probeTicketId,
    'core_delete_returned_true'=>$deleteResult===true,
    'ticket_exists_after_owner_deletion'=>$afterDelete!==null,
    'ticket_author_transferred'=>$afterDelete && (int)$afterDelete->post_author===$recipient,
    'recipient_status_http'=>$recipientResponse->get_status(),
    'recipient_count'=>$recipientCount,
    'transferred_state_valid'=>$transferState!==false,
    'author_query_count'=>count($authorQuery),
    'any_query_count_after_trash'=>count($anyTrash),
    'trash_exists_before_uninstall'=>$beforeUninstall && $beforeUninstall->post_status==='trash',
    'deleted_owner_quota_metadata_remains'=>isset($ownerRates[$owner]),
    'lost_capability_still_reports_in_review_without_availability'=>$lost['status']==='in_review' && !array_key_exists('review_availability',$lost),
    'deleted_reviewer_still_reports_in_review_without_availability'=>$deleted['status']==='in_review' && !array_key_exists('review_availability',$deleted),
    'disabled_intake_blocks_customer_status'=>$disabled===503,
    'context_publishes_ticket_disable'=>!is_wp_error($contextResult) && array_key_exists('customer_tickets',$contextResult->get_data()),
    'trash_ticket_survives_explicit_uninstall'=>get_post($probeTicketId)!==null
),'real_login_verified'=>false,'external_transport_enabled'=>false,'credentials_created'=>false));
