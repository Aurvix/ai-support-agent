<?php
verify(post_type_exists('aisa_ticket') && !get_post_type_object('aisa_ticket')->public && !get_post_type_object('aisa_ticket')->show_in_rest && !get_post_type_object('aisa_ticket')->can_export,'Actual inbox type is private, absent from REST and not exportable');
verify(has_action('admin_post_aisa_inbox')!==false && has_action('admin_post_nopriv_aisa_inbox')===false,'Inbox has authenticated admin action only');
$inboxOther=new WP_User(3); $inboxOther->set_role('administrator');
require __DIR__.'/inbox-contract.php';
inbox_contract('verify',function($uid,$admin) { wp_set_current_user($uid); });
$inboxOther->set_role('customer');
function inbox_post($uid,$nonce,$post) {
    wp_set_current_user($uid); $_POST=$post; $_REQUEST=array('_wpnonce'=>$nonce==='valid'?wp_create_nonce('aisa_admin'):'invalid-local-nonce');
    do_action('admin_post_aisa_inbox');
}
foreach (array(array(0,'valid'),array(2,'valid'),array(1,'invalid')) as $case) {
    try { inbox_post($case[0],$case[1],array('operation'=>'create','topic'=>'general')); verify(false,'Forbidden local ticket POST must fail'); }
    catch (IntegrationDenied $e) { verify(true,'Real inbox capability/nonce rejects unauthorized POST'); }
}
try { inbox_post(1,'valid',array('operation'=>'create','topic'=>'downloads')); verify(false,'Allowed local ticket POST must redirect'); }
catch (IntegrationRedirected $e) { verify(count(AISA_Inbox::visible())===1,'Real authenticated nonce POST creates private ticket'); }
$ticket=AISA_Inbox::visible()[0];
try { inbox_post(3,'valid',array('operation'=>'status','id'=>(string)$ticket['id'],'revision'=>'1','status'=>'resolved')); verify(false,'Customer ticket write must fail'); }
catch (IntegrationDenied $e) { verify(true,'Actual ticket POST denies foreign customer'); }
wp_set_current_user(1);
$previousView=$_GET; $_GET=array('view'=>'inbox','inbox_tab'=>'handoff'); ob_start(); AISA_Agent::admin(); $inboxHtml=ob_get_clean(); $_GET=$previousView;
verify(str_contains($inboxHtml,'Local review tickets') && str_contains($inboxHtml,'Update status'),'Actual dashboard renders private structured inbox controls');
verify(!str_contains(wp_json_encode(agent_method('knowledge','Local review')),'Local review:') && !str_contains(wp_json_encode(agent_method('catalog','Local review')),'Local review:'),'Tickets cannot enter approved knowledge or catalog retrieval');
verify(!str_contains(wp_json_encode(rest_get_server()->get_routes()),'aisa/v1/inbox'),'No public inbox REST route introduced');
AISA_Inbox::apply('purge',array('confirm'=>'delete_mine')); delete_option(AISA_Inbox::RETENTION);
$handoffSettings=get_option('aisa_settings'); update_option('aisa_settings',array_merge(AISA_Agent::defaults(),array('enabled'=>1,'hourly_limit'=>1000)));
$handoffResponse=AISA_Agent::handoff(new WP_REST_Request('POST','/aisa/v1/handoff'));
verify(!is_wp_error($handoffResponse) && AISA_Inbox::visible()===array(),'Real widget handoff with no configured destinations does not silently create local ticket');
update_option('aisa_settings',$handoffSettings);
$inboxAudit=get_posts(array('post_type'=>'aisa_audit','post_status'=>'private','s'=>'Local inbox','posts_per_page'=>100));
verify(count($inboxAudit)>0 && !str_contains(wp_json_encode($inboxAudit),'SECRET_CANARY') && !str_contains(wp_json_encode($inboxAudit),'download_file'),'Actual inbox audit stores fixed events/actor/site only, never request fields');
