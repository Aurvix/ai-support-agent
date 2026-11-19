<?php
require __DIR__.'/wp-load.php'; require_once ABSPATH.'wp-admin/includes/user.php';
$checks=0;
function qcheck($ok,$label) { global $checks; $checks++; if (!$ok) { throw new RuntimeException('Quality check failed: '.$label); } }
class QualityStopped extends RuntimeException {}
add_filter('wp_die_handler',function(){ return function($message,$title,$args){ throw new QualityStopped((string)($args['response']??500)); }; });
function quser($role) {
    global $wpdb; $login='quality_'.wp_generate_uuid4();
    $wpdb->insert($wpdb->users,array('user_login'=>$login,'user_pass'=>'*','user_nicename'=>$login,'user_email'=>$login.'@example.test','user_registered'=>gmdate('Y-m-d H:i:s'),'display_name'=>'Synthetic quality user'));
    $uid=(int)$wpdb->insert_id; (new WP_User($uid))->set_role($role);
    qcheck($uid>0 && get_userdata($uid)->user_pass==='*' && !wp_check_password('anything','*',$uid) && !WP_Session_Tokens::get_instance($uid)->get_all() && !get_user_meta($uid,'_application_passwords',true),'disabled-login synthetic account'); return $uid;
}
function qapi($op,$uid,$params) {
    global $wp_rest_auth_cookie; wp_set_current_user($uid); $wp_rest_auth_cookie=true; unset($_REQUEST['_wpnonce']); $_SERVER['HTTP_X_WP_NONCE']=wp_create_nonce('wp_rest');
    $r=new WP_REST_Request('POST','/aisa/v1/tickets/'.$op); $r->set_header('Content-Type','application/json'); $r->set_header('X-WP-Nonce',$_SERVER['HTTP_X_WP_NONCE']); $r->set_body(wp_json_encode($params));
    $server=rest_get_server(); $auth=$server->check_authentication(); $response=is_wp_error($auth)?rest_convert_error_to_response($auth):$server->dispatch($r);
    return apply_filters('rest_post_dispatch',$response,$server,$r);
}
function qticket($uid,$key='a') {
    wp_set_current_user($uid); $response=qapi('submit',$uid,array('scope'=>AISA_Memory::scope(),'topic'=>'general','confirm'=>true,'request_id'=>str_repeat($key,32)));
    qcheck($response->get_status()===200,'synthetic ticket setup'); return $response->get_data()['ticket']['id'];
}
$savedSettings=AISA_Agent::settings(); $roleSnapshot=get_option('wp_user_roles'); $s=$savedSettings; $s['customer_tickets']=1; $s['enabled']=1; update_option('aisa_settings',$s);
$owner=quser('subscriber'); $recipient=quser('subscriber'); $reviewer=quser('administrator');
$id=qticket($owner); $original=get_post($id)->post_content; $expiry=json_decode($original,true)['expires'];
qcheck((int)get_post_meta($id,AISA_Inbox::OWNER_META,true)===$owner,'new request sealed to owner');
wp_set_current_user($reviewer); qcheck(!is_wp_error(AISA_Handoff::review('claim',array('id'=>(string)$id,'revision'=>'1'))),'reviewer claim');
$claimed=get_post($id)->post_content; (new WP_User($reviewer))->set_role('subscriber'); wp_set_current_user($owner);
$paused=qapi('status',$owner,array('scope'=>AISA_Memory::scope()))->get_data()['tickets'][0];
qcheck($paused['status']==='in_review' && $paused['review_availability']==='unavailable' && $paused['expires']===$expiry,'lost capability pauses availability, not claim/status/deadline');
wp_set_current_user($reviewer); qcheck(is_wp_error(AISA_Handoff::review('status',array('id'=>(string)$id,'revision'=>'2','status'=>'resolved'))),'demoted reviewer denied');
wp_set_current_user(1); qcheck(AISA_Handoff::reviews()===array() && is_wp_error(AISA_Handoff::review('claim',array('id'=>(string)$id,'revision'=>'2'))),'another admin receives no stale claim access');
qcheck(get_post($id)->post_content===$claimed,'availability lookup has no state mutation');
(new WP_User($reviewer))->set_role('administrator'); wp_set_current_user($owner);
qcheck(qapi('status',$owner,array('scope'=>AISA_Memory::scope()))->get_data()['tickets'][0]['review_availability']==='assigned','existing claimant resumes only after existing capability restored');
wp_set_current_user(1); qcheck(wp_delete_user($reviewer,1),'actual reviewer deletion'); wp_set_current_user($owner);
qcheck(qapi('status',$owner,array('scope'=>AISA_Memory::scope()))->get_data()['tickets'][0]['review_availability']==='unavailable','deleted reviewer availability truthful');
wp_set_current_user(1); qcheck(AISA_Handoff::reviews()===array(),'deleted reviewer does not auto-release to other admins');
$ownerRates=get_option(AISA_Handoff::RATES); $s['customer_tickets']=0; update_option('aisa_settings',$s); wp_set_current_user($owner);
$disabled=qapi('status',$owner,array('scope'=>AISA_Memory::scope()));
qcheck($disabled->get_status()===503 && $disabled->get_headers()['Cache-Control']==='private, no-store','disabled status denied and no-store');
$denied=qapi('submit',$owner,array('scope'=>AISA_Memory::scope(),'topic'=>'general','confirm'=>true,'request_id'=>str_repeat('b',32)));
qcheck($denied->get_status()===503 && get_post($id)->post_content===$claimed && get_option(AISA_Handoff::RATES)===$ownerRates,'disabled intake changes no records/quota/deadline');
$context=new WP_REST_Request('POST','/aisa/v1/context'); $context->set_param('scope',AISA_Memory::scope());
qcheck(AISA_Agent::context($context)->get_data()['customer_tickets']===false,'context publishes live disabled state');
$s['customer_tickets']=1; update_option('aisa_settings',$s);
qcheck(qapi('status',$owner,array('scope'=>AISA_Memory::scope()))->get_data()['tickets'][0]['id']===$id,'reenable preserves existing retained ticket');
wp_set_current_user(1); qcheck(wp_delete_user($owner,$recipient),'actual owner deletion with content reassignment');
qcheck(get_post($id)===null && !isset(get_option(AISA_Handoff::RATES,array())[$owner]),'owner ticket/quota removed before reassignment');
wp_set_current_user($recipient); qcheck(qapi('status',$recipient,array('scope'=>AISA_Memory::scope()))->get_data()['tickets']===array(),'recipient gains no former-owner request');
$normal=quser('subscriber'); $normalId=qticket($normal); wp_set_current_user(1); qcheck(wp_delete_user($normal) && get_post($normalId)===null,'ordinary owner deletion cleans private request');
$localOwner=quser('administrator'); wp_set_current_user($localOwner); AISA_Inbox::apply('retention',array('days'=>'14')); $local=AISA_Inbox::apply('create',array('topic'=>'general'))['id']; wp_update_post(array('ID'=>$local,'post_status'=>'trash'));
wp_set_current_user(1); qcheck(wp_delete_user($localOwner,1) && get_post($local)===null && !isset(get_option(AISA_Inbox::RETENTION,array())[$localOwner]),'local owner deletion includes trash and retention preference');
$failureOwner=quser('subscriber'); $failureId=qticket($failureOwner); wp_set_current_user(1);
add_option(AISA_Inbox::LOCK,time());
$unrelatedOwner=quser('subscriber'); wp_set_current_user(1); qcheck(wp_delete_user($unrelatedOwner,$recipient),'unrelated account deletion does not wait on support lock');
try { wp_delete_user($failureOwner,$recipient); qcheck(false,'busy deletion must stop'); } catch (QualityStopped $e) { qcheck($e->getMessage()==='409' && get_userdata($failureOwner) && (int)get_post($failureId)->post_author===$failureOwner,'busy lock aborts account deletion without transfer'); } delete_option(AISA_Inbox::LOCK);
$deleteFailure=function($pre,$post) use($failureId) { return (int)$post->ID===$failureId?false:$pre; }; add_filter('pre_delete_post',$deleteFailure,10,2);
try { wp_delete_user($failureOwner,$recipient); qcheck(false,'failed private delete must stop'); } catch (QualityStopped $e) { qcheck($e->getMessage()==='503' && get_userdata($failureOwner) && (int)get_post($failureId)->post_author===$failureOwner && !get_option(AISA_Inbox::LOCK),'failed delete aborts and releases lock'); } remove_filter('pre_delete_post',$deleteFailure,10);
$falseSuccess=function($pre,$post) use($failureId) { return (int)$post->ID===$failureId?$post:$pre; }; add_filter('pre_delete_post',$falseSuccess,10,2);
try { wp_delete_user($failureOwner,$recipient); qcheck(false,'false success must stop'); } catch (QualityStopped $e) { qcheck($e->getMessage()==='503' && get_userdata($failureOwner) && get_post($failureId)!==null,'false-positive delete cannot allow transfer'); } remove_filter('pre_delete_post',$falseSuccess,10);
qcheck(wp_delete_user($failureOwner,$recipient) && get_post($failureId)===null,'deliberate retry succeeds after failure removed');
// Frozen ownership also hides old rows if a trusted process changed post_author directly.
$moved=qticket($recipient,'c'); wp_update_post(array('ID'=>$moved,'post_author'=>1)); wp_set_current_user(1);
qcheck(AISA_Handoff::reviews()===array(),'owner-marker mismatch hidden from reviewer queue');
qcheck(qapi('status',1,array('scope'=>AISA_Memory::scope()))->get_data()['tickets']===array(),'owner-marker mismatch cannot become a new owner status');
AISA_Handoff::cleanup(); qcheck(get_post($moved)===null,'cleanup removes mismatched owner record');
qcheck(get_option('wp_user_roles')===$roleSnapshot,'production role definitions unchanged');
update_option('aisa_settings',$savedSettings); wp_set_current_user(1);
echo wp_json_encode(array('status'=>'PASS','checks'=>$checks,'scope'=>'actual single-site WP deletion/reassignment with synthetic disabled-login users','real_login_verified'=>false,'external_sends'=>0,'new_permissions_granted'=>false));
