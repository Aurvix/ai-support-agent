<?php
// Actual WP mail/HTTP hooks and Woo payment lifecycle; intercepted synthetic peers only.
require __DIR__.'/wp-load.php';
$checks=0; $events=array('mail'=>array(),'http'=>array()); $mailResult=true; $httpResult=200;
function ncheck($ok,$label) { global $checks; $checks++; if (!$ok) { throw new RuntimeException('Notification assertion: '.$label); } }
function nmethod($method,...$args) { return (new ReflectionMethod('AISA_Agent',$method))->invoke(null,...$args); }
function nresponse($status) { return array('headers'=>array(),'response'=>array('code'=>$status,'message'=>'Synthetic'),'body'=>'synthetic-upstream-private-marker','cookies'=>array()); }
add_filter('pre_wp_mail',function($pre,$args) use (&$events,&$mailResult) {
    if ($args['to']!=='notification-fixture@example.test') { return $pre; }
    $events['mail'][]=$args; return $mailResult;
},PHP_INT_MAX,2);
add_filter('pre_http_request',function($pre,$args,$url) use (&$events,&$httpResult) {
    if (!in_array($url,array('https://notification.fixture.invalid/relay','https://api.telegram.org/bot0:synthetic_noncredential/sendMessage'),true)) { return $pre; }
    $events['http'][]=array('url'=>$url,'args'=>$args);
    return is_wp_error($httpResult)?$httpResult:nresponse($httpResult);
},PHP_INT_MAX,3);
function nsettings($enabled=true) {
    $s=AISA_Agent::defaults(); $s['enabled']=1;
    if ($enabled) {
        $s['notify_email']='notification-fixture@example.test';
        // These are unusable synthetic peer identifiers, not provisioned credentials.
        $s['telegram_secret']=nmethod('encrypt','0:synthetic_noncredential'); $s['telegram_chat']='synthetic-chat';
        $s['webhook_secret']=nmethod('encrypt','https://notification.fixture.invalid/relay');
    }
    update_option('aisa_settings',$s); return $s;
}
function naudit() { return wp_list_pluck(get_posts(array('post_type'=>'aisa_audit','post_status'=>'private','posts_per_page'=>100,'orderby'=>'ID','order'=>'DESC')),'post_title'); }
function norder($status='pending') {
    $o=wc_create_order(array('customer_id'=>2,'status'=>$status));
    $o->set_billing_email('private-synthetic-customer@example.test'); $o->set_billing_first_name('PrivateSyntheticName');
    $o->set_customer_note('PrivateSyntheticTranscript'); $o->set_total('5'); $o->save(); return $o;
}
wp_set_current_user(1); $roles=get_option('wp_user_roles'); nsettings(false);
nmethod('notify','human_support_requested');
ncheck($events===array('mail'=>array(),'http'=>array()),'unconfigured notifications attempt no transport');
$s=nsettings();
$r=new WP_REST_Request('POST','/aisa/v1/handoff'); $r->set_body_params(array('message'=>'PrivateSyntheticTranscript','email'=>'private-synthetic-customer@example.test','order_id'=>99999));
$result=AISA_Agent::handoff($r);
ncheck(!is_wp_error($result) && $result->get_status()===200,'explicit human-handoff callback returns support guidance');
ncheck(count($events['mail'])===1 && count($events['http'])===2,'one attempt per configured channel');
$mail=$events['mail'][0];
ncheck($mail['subject']==='AI Support · human_support_requested','fixed mail event subject');
$payload=json_decode($mail['message'],true);
ncheck(array_keys($payload)===array('event','site','order_id') && $payload['event']==='human_support_requested' && $payload['order_id']===0,'handoff contains fixed event metadata only');
$telegram=$events['http'][0]; $relay=$events['http'][1];
ncheck($telegram['args']['body']['chat_id']==='synthetic-chat' && json_decode($telegram['args']['body']['text'],true)===$payload,'Telegram typed metadata matches mail');
$relayBody=json_decode($relay['args']['body'],true);
ncheck(array_keys($relayBody)===array('text','content','event') && $relayBody['event']===$payload && $relayBody['text']===$mail['message'] && $relayBody['content']===$mail['message'],'relay JSON envelope contains the same minimized metadata');
ncheck($relay['args']['headers']['Content-Type']==='application/json','relay content type');
foreach ($events['http'] as $attempt) {
    ncheck($attempt['args']['timeout']===8 && $attempt['args']['redirection']===0 && $attempt['args']['reject_unsafe_urls']===true,'real safe HTTP options bounded, no redirects');
}
ncheck(!str_contains(wp_json_encode($events),'PrivateSyntheticTranscript') && !str_contains(wp_json_encode($events),'private-synthetic-customer'),'handoff excludes caller text/contact/order override');
// Native Woo payment hook, no gateway, money movement or real transaction.
$events=array('mail'=>array(),'http'=>array()); $order=norder();
AISA_Agent::purchase_notice($order->get_id());
ncheck(!$events['mail'] && !$events['http'] && !$order->get_meta('_aisa_notified'),'unpaid order creates no notice/marker');
$order->payment_complete(); $order=wc_get_order($order->get_id());
ncheck($order->is_paid() && (bool)$order->get_meta('_aisa_notified'),'actual Woo payment-complete hook stores attempt marker');
ncheck(count($events['mail'])===1 && count($events['http'])===2,'payment-complete dispatches one intercepted attempt per channel');
$paid=json_decode($events['mail'][0]['message'],true);
ncheck($paid['event']==='purchase_paid' && $paid['order_id']===$order->get_id() && count($paid)===3,'paid notice contains only event/site/order ID');
ncheck(!str_contains(wp_json_encode($events),'PrivateSyntheticName') && !str_contains(wp_json_encode($events),'PrivateSyntheticTranscript') && !str_contains(wp_json_encode($events),'private-synthetic-customer'),'Woo billing/contact/note absent from all channel payloads');
$before=$events; do_action('woocommerce_payment_complete',$order->get_id());
ncheck($events===$before,'sequential repeated payment hook does not resend');
// Errors remain local and redacted; no automated retry is claimed.
$events=array('mail'=>array(),'http'=>array()); $mailResult=false; $httpResult=new WP_Error('timeout','synthetic-upstream-private-marker'); $failed=norder(); $failed->payment_complete();
ncheck(count($events['mail'])===1 && count($events['http'])===2,'one channel failure does not prevent remaining channels attempting');
$audit=naudit();
foreach(array('Email notification failed','Telegram notification failed','Webhook notification failed') as $title) { ncheck(in_array($title,$audit,true),'generic failure audit '.$title); }
ncheck(!str_contains(wp_json_encode($audit),'synthetic-upstream-private-marker'),'upstream transport error text is not audited');
$before=$events; do_action('woocommerce_payment_complete',$failed->get_id());
ncheck($events===$before && (bool)wc_get_order($failed->get_id())->get_meta('_aisa_notified'),'ambiguous failed attempts remain marked; no automatic retry');
foreach(array(302,429,503) as $status) {
    $events=array('mail'=>array(),'http'=>array()); $mailResult=true; $httpResult=$status;
    $beforeCounts=array_count_values(naudit());
    nmethod('notify','human_support_requested');
    ncheck(count($events['mail'])===1 && count($events['http'])===2,'redirect/rate/server response attempts are bounded for '.$status);
    ncheck(!str_contains(wp_json_encode(naudit()),'synthetic-upstream-private-marker'),'HTTP error body excluded from audit for '.$status);
    $afterCounts=array_count_values(naudit());
    foreach(array('Telegram notification failed','Webhook notification failed') as $title) { ncheck(($afterCounts[$title]??0)===($beforeCounts[$title]??0)+1,'each HTTP failure adds a fresh generic channel audit for '.$status); }
}
$s['telegram_secret']=nmethod('encrypt','not-a-token'); $s['webhook_secret']=nmethod('encrypt','http://notification.fixture.invalid/relay'); update_option('aisa_settings',$s);
$events=array('mail'=>array(),'http'=>array()); nmethod('notify','human_support_requested');
ncheck(count($events['mail'])===1 && !$events['http'],'malformed token and non-HTTPS relay skip HTTP attempts');
ncheck(get_option('wp_user_roles')===$roles,'existing role definitions unchanged');
foreach(array(1,2,3) as $uid) { ncheck(get_userdata($uid)->user_pass==='*' && !WP_Session_Tokens::get_instance($uid)->get_all() && !get_user_meta($uid,'_application_passwords',true),'no usable credentials/sessions provisioned'); }
echo wp_json_encode(array('status'=>'PASS','checks'=>$checks,'plugin_version'=>AISA_VERSION,'storage'=>\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'hpos':'cpt','transport'=>'real_wp_mail_and_safe_http_hooks_intercepted','payment_hook'=>'actual_woocommerce_payment_complete_synthetic_order','delivery_verified'=>false,'real_credentials_created'=>false,'external_sends'=>0,'limitations'=>array('attempt marker is not delivery receipt','sequential deduplication only; concurrent workers unverified','no automatic retries or destination idempotency contract')));
