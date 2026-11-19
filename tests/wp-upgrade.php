<?php
require __DIR__.'/wp-load.php'; require_once ABSPATH.'wp-admin/includes/plugin.php';
$checks=0; $phase=getenv('AISA_UPGRADE_PHASE');
function acheck($ok,$label) { global $checks; $checks++; if (!$ok) { throw new RuntimeException('Upgrade check: '.$label); } }
function aticket($uid,$key) {
    wp_set_current_user($uid); $r=new WP_REST_Request('POST','/aisa/v1/tickets/submit'); $r->set_header('Content-Type','application/json'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_body(wp_json_encode(array('scope'=>AISA_Memory::scope(),'topic'=>'general','confirm'=>true,'request_id'=>str_repeat($key,32))));
    $response=AISA_Handoff::submit($r); acheck(!is_wp_error($response),'seed old ticket'); return $response->get_data()['ticket']['id'];
}
function astatus($uid) {
    wp_set_current_user($uid); $r=new WP_REST_Request('POST','/aisa/v1/tickets/status'); $r->set_header('Content-Type','application/json'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_body(wp_json_encode(array('scope'=>AISA_Memory::scope()))); return AISA_Handoff::status($r)->get_data()['tickets'];
}
if ($phase==='seed') {
    acheck(AISA_VERSION==='0.1.7','old archive actually loaded');
    $s=AISA_Agent::settings(); $s['customer_tickets']=1; $s['enabled']=1; $s['title']='Upgrade synthetic branding'; $s['daily_limit']=77; update_option('aisa_settings',$s);
    $customer=aticket(2,'a'); wp_set_current_user(1); AISA_Handoff::review('claim',array('id'=>(string)$customer,'revision'=>'1'));
    $local=AISA_Inbox::apply('create',array('topic'=>'knowledge'))['id'];
    global $wpdb; $wpdb->insert($wpdb->users,array('user_login'=>'upgrade_disabled','user_pass'=>'*','user_nicename'=>'upgrade_disabled','user_email'=>'upgrade_disabled@example.test','user_registered'=>gmdate('Y-m-d H:i:s'),'display_name'=>'Synthetic upgrade account')); $inactiveOwner=(int)$wpdb->insert_id; (new WP_User($inactiveOwner))->set_role('subscriber');
    $inactive=aticket($inactiveOwner,'b');
    acheck(get_post_meta($customer,'aisa_ticket_owner',true)==='' && get_post_meta($local,'aisa_ticket_owner',true)==='','legacy records genuinely unsealed');
    file_put_contents('/upgrade-fixture.json',wp_json_encode(array('customer'=>$customer,'local'=>$local,'inactive'=>$inactive,'inactive_owner'=>$inactiveOwner,'settings'=>$s,'roles'=>get_option('wp_user_roles'),'content'=>array($customer=>get_post($customer)->post_content,$local=>get_post($local)->post_content,$inactive=>get_post($inactive)->post_content))));
    add_option('aisa_inbox_lock',time());
} else {
    $f=json_decode(file_get_contents('/upgrade-fixture.json'),true);
    if ($phase==='busy' || $phase==='fail-meta') {
        acheck(version_compare(AISA_VERSION,'0.1.8','>=') && !get_option('aisa_ticket_owners_ready'),'new code leaves interrupted migration pending');
        acheck(astatus(2)===array(),'unsealed records never exposed during pending migration');
        if ($phase==='busy') { acheck(get_option('aisa_inbox_lock')!==false,'existing lock not taken over'); delete_option('aisa_inbox_lock'); }
        else { AISA_Inbox::cleanup(); AISA_Handoff::cleanup(); }
        foreach ($f['content'] as $id=>$content) { acheck(get_post($id)!==null && get_post($id)->post_content===$content && get_post_meta($id,'aisa_ticket_owner',true)==='','pending cleanup preserves unsealed legacy data'); }
    } elseif ($phase==='migrate') {
        acheck(version_compare(AISA_VERSION,'0.1.8','>=') && (int)get_option('aisa_ticket_owners_ready')===1,'file upgrade migrates on init without reactivation');
        foreach ($f['content'] as $id=>$content) { acheck(get_post($id)->post_content===$content && (int)get_post_meta($id,'aisa_ticket_owner',true)===(int)get_post($id)->post_author,'legacy state/owner preserved and sealed'); }
        acheck(astatus(2)[0]['id']===$f['customer'] && astatus(2)[0]['review_availability']==='assigned','old claimed customer request remains available to owner');
        wp_set_current_user(1); acheck(AISA_Inbox::visible()[0]['id']===$f['local'],'old private local ticket remains creator-only');
        acheck(get_option('aisa_settings')===$f['settings'] && get_option('wp_user_roles')===$f['roles'],'settings/feature flags/roles unchanged');
        deactivate_plugins('ai-support-agent/ai-support-agent.php',false);
    } elseif ($phase==='inactive') {
        acheck(!class_exists('AISA_Agent'),'account deletion really happens while plugin inactive'); require_once ABSPATH.'wp-admin/includes/user.php';
        acheck(wp_delete_user($f['inactive_owner'],2) && (int)get_post($f['inactive'])->post_author===2,'core transfers post while plugin hook absent');
        acheck((int)get_post_meta($f['inactive'],'aisa_ticket_owner',true)===$f['inactive_owner'],'frozen owner marker survives inactive transfer');
        acheck(!is_wp_error(activate_plugin('ai-support-agent/ai-support-agent.php','',false,false)),'reactivation succeeds');
    } elseif ($phase==='check') {
        acheck((int)get_option('aisa_ticket_owners_ready')===1 && (int)get_post_meta($f['inactive'],'aisa_ticket_owner',true)===$f['inactive_owner'],'completed migration never reseals transferred author');
        $own=astatus(2); acheck(count($own)===1 && $own[0]['id']===$f['customer'],'recipient cannot read inactive former-owner request');
        AISA_Handoff::cleanup(); acheck(get_post($f['inactive'])===null && !isset(get_option(AISA_Handoff::RATES,array())[$f['inactive_owner']]),'reactivation cleanup removes orphan/mismatched record and quota');
        acheck(get_post($f['customer'])->post_content===$f['content'][$f['customer']] && get_post($f['local'])->post_content===$f['content'][$f['local']],'other retained records unchanged');
        acheck(get_option('aisa_settings')===$f['settings'] && get_option('wp_user_roles')===$f['roles'],'no configuration/permission expansion');
    }
}
echo wp_json_encode(array('status'=>'PASS','phase'=>$phase,'checks'=>$checks,'scope'=>'0.1.7 archive to candidate, synthetic in-memory database only','real_login_verified'=>false));
