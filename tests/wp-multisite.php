<?php
// Real core network APIs with pre-existing disabled-login fixture accounts only.
require __DIR__.'/wp-load.php'; require_once ABSPATH.'wp-admin/includes/plugin.php'; require_once ABSPATH.'wp-admin/includes/upgrade.php';
$checks=0; $phase=getenv('AISA_MS_PHASE');
add_filter('wp_die_handler',function(){ return function($message){ throw new RuntimeException('Native WP stopped: '.(is_string($message)?strip_tags($message):'error').' Trace: '.wp_json_encode($GLOBALS['ms_delete_trace']??array())); }; });
function mscheck($ok,$label) { global $checks; $checks++; if (!$ok) { throw new RuntimeException('Multisite assertion: '.$label); } }
if ($phase==='convert') {
    wp_set_current_user(1);
    // A conversion request began in single-site mode; initialize the core MS table-name map first.
    global $wpdb; foreach ($wpdb->tables('ms_global',true) as $table=>$name) { $wpdb->$table=$name; }
    install_network();
    $result=populate_network(1,'aisa.local.test','fixture_admin@example.test','Synthetic isolated network','/',false);
    mscheck(!is_wp_error($result),'core creates network schema/meta without installer or new user');
    echo wp_json_encode(array('status'=>'PASS','phase'=>$phase,'checks'=>$checks)); return;
}
mscheck(is_multisite(),'actual multisite bootstrap');
if ($phase==='setup') {
    wp_set_current_user(1);
    $site=wpmu_create_blog('aisa.local.test','/second/','Synthetic second site',1,array('public'=>0));
    mscheck(!is_wp_error($site) && (int)$site===2,'actual second site initialization');
    switch_to_blog($site);
    foreach (array('woocommerce/woocommerce.php','ai-support-agent/ai-support-agent.php') as $plugin) { mscheck(!is_wp_error(activate_plugin($plugin,'',false,false)),'per-site activation '.$plugin); }
    add_user_to_blog($site,2,'subscriber'); add_user_to_blog($site,3,'subscriber');
    restore_current_blog();
    mscheck(count(get_sites(array('fields'=>'ids')))===2,'two real site records exist');
    foreach (array(1,2,3) as $uid) { mscheck(get_userdata($uid)->user_pass==='*' && !WP_Session_Tokens::get_instance($uid)->get_all() && !get_user_meta($uid,'_application_passwords',true),'no usable credentials or sessions created'); }
} elseif ($phase==='storage') {
    mscheck(get_current_blog_id()===2,'fresh second-site boot before Woo storage selection');
    global $wpdb;
    mscheck((bool)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.'actionscheduler_actions')),'native Action Scheduler schema initialized on subsite');
    update_option('woocommerce_custom_orders_table_enabled',getenv('AISA_MS_STORAGE')==='cpt'?'no':'yes'); update_option('woocommerce_custom_orders_table_data_sync_enabled','no');
    mscheck(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()===(getenv('AISA_MS_STORAGE')==='hpos'),'requested subsite Woo storage mode really active');
} elseif ($phase==='isolation') {
    wp_set_current_user(1);$trackStaff44=new ReflectionMethod('AISA_Admin_Assistant','track');$staffKey44=new ReflectionMethod('AISA_Admin_Assistant','key');$mainStaff44=$staffKey44->invoke(null);mscheck($trackStaff44->invoke(null)===true,'current-site administrator chat index tracks safely');set_transient($mainStaff44,'Synthetic main-site admin chat only',900);
    switch_to_blog(2);$secondStaff44=$staffKey44->invoke(null);mscheck($trackStaff44->invoke(null)===true&&$secondStaff44!==$mainStaff44,'different blog has a separate administrator chat index/key');set_transient($secondStaff44,'Synthetic second-site admin chat only',900);restore_current_blog();
    mscheck(in_array($mainStaff44,(array)get_user_meta(1,'aisa_staff_keys_1',true),true)&&in_array($secondStaff44,(array)get_user_meta(1,'aisa_staff_keys_2',true),true),'both site indexes coexist without replacement');
    function msapi($op,$uid,$params) {
        global $wp_rest_auth_cookie; wp_set_current_user($uid); $wp_rest_auth_cookie=true; unset($_REQUEST['_wpnonce']); $_SERVER['HTTP_X_WP_NONCE']=wp_create_nonce('wp_rest');
        $r=new WP_REST_Request('POST','/aisa/v1/tickets/'.$op); $r->set_header('Content-Type','application/json'); $r->set_header('X-WP-Nonce',$_SERVER['HTTP_X_WP_NONCE']); $r->set_body(wp_json_encode($params));
        $server=rest_get_server(); $auth=$server->check_authentication(); $response=is_wp_error($auth)?rest_convert_error_to_response($auth):$server->dispatch($r);
        return apply_filters('rest_post_dispatch',$response,$server,$r);
    }
    function msmethod($name,...$args) { return (new ReflectionMethod('AISA_Agent',$name))->invoke(null,...$args); }
    function msseed($label,$topic) {
        wp_set_current_user(1); AISA_Inbox::migrate_owners();
        mscheck(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()===(getenv('AISA_MS_STORAGE')==='hpos'),'actual Woo storage mode in switched site');
        $s=AISA_Agent::defaults(); $s['enabled']=1; $s['memory']=1; $s['customer_tickets']=1; $s['title']=$label; update_option('aisa_settings',$s);
        $page=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>$label.'policy','post_content'=>$label.' public policy only.'));
        $record=msmethod('source_id',$page); update_post_meta($record,'aisa_approved_hash',msmethod('review_hash',get_post($record)));
        $product=new WC_Product_Simple(); $product->set_name($label.' product'); $product->set_regular_price('5'); $product->save();
        $order=wc_create_order(array('customer_id'=>2,'status'=>'pending')); $order->add_product($product,1); $order->calculate_totals(); $order->save();
        wp_set_current_user(2); $scope=AISA_Memory::scope();
        $ticket=msapi('submit',2,array('scope'=>$scope,'topic'=>$topic,'confirm'=>true,'request_id'=>str_repeat('a',32)));
        mscheck($ticket->get_status()===200,'site-specific structured ticket created');
        $context=AISA_Memory::open(str_repeat('b',32),$scope);
        mscheck(AISA_Memory::commit($context,array(array('id'=>(int)$record,'kind'=>'knowledge','stamp'=>msmethod('review_hash',get_post($record))))),'real site-specific memory transient saved');
        return array('scope'=>$scope,'ticket'=>$ticket->get_data()['ticket']['id'],'memory_key'=>$context['key'],'page'=>$page,'record'=>$record,'topic'=>$topic,'label'=>$label);
    }
    $a=msseed('Ambernetwork','orders'); $roleA=get_option('wp_user_roles');
    switch_to_blog(2); wp_set_current_user(1);
    mscheck(AISA_Agent::settings()['enabled']===0 && AISA_Agent::settings()['customer_tickets']===0,'second site starts with safe independent defaults');
    mscheck(msmethod('knowledge','Ambernetwork')===array(),'second site cannot retrieve first site knowledge');
    $b=msseed('Berylnetwork','downloads'); $roleB=get_option('wp_user_roles');
    mscheck($a['scope']!==$b['scope'] && $a['memory_key']!==$b['memory_key'],'real blog identity scopes and memory keys differ');
    mscheck(msapi('status',2,array('scope'=>$a['scope']))->get_status()===409,'first-site scope rejected on second-site endpoint');
    mscheck(msapi('submit',2,array('scope'=>$a['scope'],'topic'=>'orders','confirm'=>true,'request_id'=>str_repeat('c',32)))->get_status()===409,'cross-site stale scope cannot create a request');
    $own=msapi('status',2,array('scope'=>$b['scope']))->get_data()['tickets'];
    mscheck(count($own)===1 && $own[0]['topic']==='downloads','second site returns only its customer ticket');
    mscheck(get_transient($a['memory_key'])===false && count(AISA_Memory::open(str_repeat('b',32),$b['scope'])['state']['turns'])===1,'memory lookup cannot cross real site cache namespace');
    $orders=AISA_Agent::orders()->get_data()['orders'];
    mscheck(count($orders)===1 && str_contains(wp_json_encode($orders),'Berylnetwork product') && !str_contains(wp_json_encode($orders),'Ambernetwork product'),'second-site actual Woo query does not leak first-site order');
    mscheck(count(msmethod('knowledge','Berylnetwork'))===1 && msmethod('knowledge','Ambernetwork')===array(),'approved source retrieval remains site-specific');
    $ratesB=get_option(AISA_Handoff::RATES); restore_current_blog(); wp_set_current_user(2);
    mscheck(get_current_blog_id()===1 && AISA_Agent::settings()['title']==='Ambernetwork','restore_current_blog restores settings identity');
    $own=msapi('status',2,array('scope'=>$a['scope']))->get_data()['tickets'];
    mscheck(count($own)===1 && $own[0]['topic']==='orders','first-site ticket unchanged after second-site writes');
    $orders=AISA_Agent::orders()->get_data()['orders'];
    mscheck(count($orders)===1 && str_contains(wp_json_encode($orders),'Ambernetwork product') && !str_contains(wp_json_encode($orders),'Berylnetwork product'),'first-site order cache restores correctly');
    mscheck(get_transient($b['memory_key'])===false && count(AISA_Memory::open(str_repeat('b',32),$a['scope'])['state']['turns'])===1,'first-site memory restores without cross-site replay');
    mscheck(count(msmethod('knowledge','Ambernetwork'))===1 && msmethod('knowledge','Berylnetwork')===array(),'first-site approved facts remain independent');
    mscheck(count(get_option(AISA_Handoff::RATES)[2])===1 && count($ratesB[2])===1,'submission counters independent');
    // Observe membership policy; do not silently add a membership or recovery permission rule.
    switch_to_blog(2); remove_user_from_blog(2,2); wp_set_current_user(2);
    $former=msapi('status',2,array('scope'=>$b['scope']));
    $membership=array('account_exists'=>(bool)get_userdata(2),'site_member'=>is_user_member_of_blog(2,2),'own_status_http'=>$former->get_status(),'own_status_count'=>count($former->get_data()['tickets']??array()));
    mscheck(!$membership['site_member'] && $membership['account_exists'],'actual site membership removed, global account retained');
    add_user_to_blog(2,2,'subscriber');
    mscheck(get_option('wp_user_roles')===$roleB,'second-site role definitions unchanged by test actions');
    restore_current_blog(); mscheck(get_option('wp_user_roles')===$roleA,'first-site role definitions unchanged');
    file_put_contents('/multisite-fixture.json',wp_json_encode(array('a'=>$a,'b'=>$b,'membership_observation'=>$membership)));
} elseif ($phase==='fresh-second') {
    mscheck(get_current_blog_id()===2,'fresh subdirectory request selects real second site');
    mscheck(AISA_Agent::settings()['title']==='Berylnetwork','fresh site bootstrap loads its own settings');
    wp_set_current_user(2); mscheck(AISA_Memory::scope()===json_decode(file_get_contents('/multisite-fixture.json'),true)['b']['scope'],'fresh subsite scope agrees with real switch context');
} elseif ($phase==='global-delete') {
    $ids=array();
    foreach (array(1,2) as $site) {
        switch_to_blog($site); wp_set_current_user(3);
        $r=new WP_REST_Request('POST','/aisa/v1/tickets/submit'); $r->set_header('Content-Type','application/json'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_body(wp_json_encode(array('scope'=>AISA_Memory::scope(),'topic'=>'general','confirm'=>true,'request_id'=>str_repeat('c',32))));
        $response=AISA_Handoff::submit($r); mscheck(!is_wp_error($response),'global deletion fixture request'); $ids[$site]=$response->get_data()['ticket']['id']; restore_current_blog();
    }
    wp_set_current_user(1); require_once ABSPATH.'wp-admin/includes/ms.php';
    mscheck(wpmu_delete_user(3) && get_userdata(3)===false,'actual network user deletion');
    foreach ($ids as $site=>$id) {
        switch_to_blog($site);
        mscheck(get_post($id)===null,'core global deletion removes authored request on each member site');
        AISA_Handoff::cleanup(); AISA_Inbox::cleanup();
        mscheck(!isset(get_option(AISA_Handoff::RATES,array())[3]),'site cleanup removes deleted global owner quota'); restore_current_blog();
    }
} elseif ($phase==='switched-removal') {
    $f=json_decode(file_get_contents('/multisite-fixture.json'),true); $mainSettings=get_option('aisa_settings'); $mainKnowledge=get_post($f['a']['record'])->post_content;
    file_put_contents('/multisite-main.json',wp_json_encode(array('settings'=>$mainSettings,'knowledge'=>$mainKnowledge)));
    switch_to_blog(2); wp_set_current_user(1);
    // Observe the native Woo guard; never override its result or delete directly.
    add_filter('pre_delete_post',function($pre,$post){
        $store=wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class);
        $table=(new ReflectionProperty($store,'orders_table_name'))->getValue($store);
        $GLOBALS['ms_delete_trace']=array('id'=>$post->ID,'type'=>$post->post_type,'site'=>get_current_blog_id(),'pre_return_is_null'=>$pre===null,'woo_store_table'=>$table,'current_orders_table'=>\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name());
        return $pre;
    },PHP_INT_MAX,2);
    define('WP_UNINSTALL_PLUGIN','ai-support-agent/ai-support-agent.php'); define('AISA_DELETE_DATA',true);
    $stopped=false;
    try { require ABSPATH.'wp-content/plugins/ai-support-agent/uninstall.php'; }
    catch (RuntimeException $e) { if (!str_contains($e->getMessage(),'AI Support data removal is incomplete')) { throw $e; } $stopped=true; }
    $removalObservation=array('controlled_stop'=>$stopped,'trace'=>$GLOBALS['ms_delete_trace']??null);
    if ($stopped) {
        mscheck(get_option('aisa_settings')!==false,'native deletion refusal retains settings for retry');
        mscheck(get_post($GLOBALS['ms_delete_trace']['id'])!==null,'native deletion refusal preserves the actual refused record; earlier deletions may have completed');
    } else { mscheck(get_option('aisa_settings')===false,'switched removal completed without a native refusal'); }
    restore_current_blog();
    mscheck(get_option('aisa_settings')===$mainSettings && get_post($f['a']['record'])->post_content===$mainKnowledge && get_post($f['a']['ticket'])!==null,'switched removal never touches other-site plugin data');
} elseif ($phase==='site-data-removal') {
    $f=json_decode(file_get_contents('/multisite-fixture.json'),true);
    mscheck(get_current_blog_id()===2,'data removal boots directly in target subsite');
    wp_set_current_user(1);
    define('WP_UNINSTALL_PLUGIN','ai-support-agent/ai-support-agent.php'); define('AISA_DELETE_DATA',true);
    require ABSPATH.'wp-content/plugins/ai-support-agent/uninstall.php';
    mscheck(get_option('aisa_settings')===false && get_post($f['b']['ticket'])===null && get_post($f['b']['record'])===null,'explicit data-removal routine clears current subsite records/options');
    mscheck(get_post($f['b']['page'])!==null && count(wc_get_orders(array('customer_id'=>2,'return'=>'ids')))===1,'subsite source page and Woo order preserved');
} elseif ($phase==='removal-check') {
    wp_set_current_user(1);$survivingStaff44=(new ReflectionMethod('AISA_Admin_Assistant','key'))->invoke(null);mscheck(get_transient($survivingStaff44)==='Synthetic main-site admin chat only'&&in_array($survivingStaff44,(array)get_user_meta(1,'aisa_staff_keys_1',true),true),'subsite uninstall preserves other-site administrator chat and index');
    $f=json_decode(file_get_contents('/multisite-fixture.json'),true); $main=json_decode(file_get_contents('/multisite-main.json'),true);
    mscheck(get_current_blog_id()===1,'post-removal check boots main site');
    mscheck(get_option('aisa_settings')===$main['settings'] && get_post($f['a']['record'])->post_content===$main['knowledge'] && get_post($f['a']['ticket'])!==null,'other site settings/knowledge/request preserved');
    mscheck(count(wc_get_orders(array('customer_id'=>2,'return'=>'ids')))===1,'other site Woo order preserved');
    foreach(array(1,2) as $uid) { mscheck(get_userdata($uid)->user_pass==='*' && !WP_Session_Tokens::get_instance($uid)->get_all() && !get_user_meta($uid,'_application_passwords',true),'surviving synthetic users still have no usable credentials/sessions'); }
}
echo wp_json_encode(array('status'=>'PASS','phase'=>$phase,'checks'=>$checks,'scope'=>'real disposable subdirectory multisite; synthetic principals, no signed login','membership_observation'=>$phase==='isolation'?$membership:null,'removal_observation'=>$removalObservation??null,'credentials_created'=>false,'external_sends'=>0));
