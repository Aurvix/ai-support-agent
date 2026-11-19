<?php
require __DIR__.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
$checks=0;
function verify($condition,$label) {
    global $checks; $checks++;
    if (!$condition) { throw new RuntimeException('Integration assertion failed: '.$label); }
}
verify(defined('WC_VERSION') && class_exists('AISA_Agent'),'Real WooCommerce and agent loaded on a fresh request');
verify(is_plugin_active('ai-support-agent/ai-support-agent.php'),'Agent activation persists in real WordPress options');
verify(AISA_Agent::settings()['enabled']===0,'Chat remains disabled after activation');
verify((bool)wp_next_scheduled('aisa_index_batch'),'Real activation hook schedules indexing');
verify(post_type_exists('aisa_knowledge') && !get_post_type_object('aisa_knowledge')->public,'Knowledge post type is private');
verify(get_userdata(1)->user_pass==='*' && get_userdata(2)->user_pass==='*' && get_userdata(3)->user_pass==='*','Synthetic accounts have disabled login passwords');
verify(!wp_check_password('fixture-anything','*',2),'Disabled synthetic password cannot authenticate');
foreach(array(1,2,3) as $id) {
    verify(!get_user_meta($id,'_application_passwords',true) && WP_Session_Tokens::get_instance($id)->get_all()===array(),'No application password or login session exists for synthetic user '.$id);
}
$a=new WP_User(2); $a->set_role('customer'); $b=new WP_User(3); $b->set_role('customer');

// Real WP nonce/authentication filters run against an explicitly set synthetic principal.
// No login cookies, sessions or Application Password credentials are created.
function api($path,$uid=0,$params=array(),$nonce='valid') {
    global $wp_rest_auth_cookie;
    wp_set_current_user($uid); $wp_rest_auth_cookie=$uid>0;
    unset($_REQUEST['_wpnonce'],$_SERVER['HTTP_X_WP_NONCE']);
    if ($uid && $nonce==='valid') { $_SERVER['HTTP_X_WP_NONCE']=wp_create_nonce('wp_rest'); }
    elseif ($nonce==='invalid') { $_SERVER['HTTP_X_WP_NONCE']='not-a-valid-test-nonce'; }
    elseif ($nonce==='expired') { $_SERVER['HTTP_X_WP_NONCE']=substr(wp_hash((wp_nonce_tick('wp_rest')-2).'|wp_rest|'.$uid.'|'.wp_get_session_token(),'nonce'),-12,10); }
    $server=rest_get_server();
    $error=$server->check_authentication();
    if (is_wp_error($error)) { return rest_convert_error_to_response($error); }
    $post=in_array($path,array('chat','context','memory_reset'),true);
    $request=new WP_REST_Request($post?'POST':'GET','/aisa/v1/'.$path); if ($post) { $request->set_body_params($params); } else { $request->set_query_params($params); }
    return $server->dispatch($request);
}
verify(api('orders')->get_status()===401,'Anonymous REST orders rejected');
verify(api('downloads')->get_status()===401,'Anonymous REST downloads rejected');
verify(api('orders',2,array(),'missing')->get_status()===401,'Missing cookie nonce loses authenticated context');
verify(api('downloads',2,array(),'invalid')->get_status()===403,'Invalid REST nonce rejected by real WordPress');
verify(api('downloads',2,array(),'expired')->get_status()===403,'Expired REST nonce rejected by real WordPress');
verify(api('orders',2)->get_status()===200,'Valid WP nonce permits synthetic authenticated principal');

// Actual WC CRUD and permission engine, not test doubles.
$product=new WC_Product_Simple(); $product->set_name('Synthetic downloadable guide'); $product->set_status('publish');
$product->set_regular_price('5'); $product->set_virtual(true); $product->set_downloadable(true); $product->set_download_limit(-1); $product->set_download_expiry(-1);
$uploads=wp_upload_dir(); $directory=$uploads['basedir'].'/woocommerce_uploads'; wp_mkdir_p($directory); file_put_contents($directory.'/synthetic.txt','Synthetic download fixture only.');
$file=new WC_Product_Download(); $file->set_id('synthetic-file'); $file->set_name('Synthetic guide'); $file->set_file($directory.'/synthetic.txt');
$product->set_downloads(array($file)); $product->save();
function order_for($uid,$product,$status='completed') {
    $order=wc_create_order(array('customer_id'=>$uid));
    if (is_wp_error($order)) { throw new RuntimeException($order->get_error_message()); }
    $order->set_billing_email('fixture_'.$uid.'@example.test'); $order->add_product($product,1); $order->calculate_totals(); $order->set_status($status); $order->save();
    wc_downloadable_product_permissions($order->get_id());
    return $order;
}
$orderA=order_for(2,$product); $orderB=order_for(3,$product);
$orders=api('orders',2,array('customer_id'=>3,'order_id'=>$orderB->get_id()))->get_data()['orders'];
verify(count($orders)===1 && (string)$orders[0]['number']===(string)$orderA->get_order_number(),'Account A cannot select B order through request parameters');
$orders=api('orders',3)->get_data()['orders'];
verify(count($orders)===1 && (string)$orders[0]['number']===(string)$orderB->get_order_number(),'Account B sees only B order');
$response=api('downloads',2,array('customer_id'=>3));
verify($response->get_status()===200 && count($response->get_data()['downloads'])===1,'Completed owned order has available download');
verify($response->get_headers()['Cache-Control']==='private, no-store','Real private REST response disables shared caching');
$allowed=$response->get_data()['downloads'][0];
verify(array_keys($allowed)===array('name','url') && $allowed['url']===wc_get_account_endpoint_url('downloads') && !str_contains(wp_json_encode($response->get_data()),'download_file='),'Plugin returns native account Downloads page, never a signed file URL');
$store=WC_Data_Store::load('customer-download');
$permissions=$store->get_downloads(array('order_id'=>$orderA->get_id()));
verify(count($permissions)===1,'Real permission row exists for completed order');
$permission=$permissions[0]; $permission->set_access_expires(time()-DAY_IN_SECONDS); $permission->save();
verify(api('downloads',2)->get_data()['downloads']===array(),'Expired Woo permission excluded');
$permission->set_access_expires(null); $permission->set_downloads_remaining('0'); $permission->save();
verify(api('downloads',2)->get_data()['downloads']===array(),'Exhausted download count excluded');
$permission->set_downloads_remaining(''); $permission->save();
verify(count(api('downloads',2)->get_data()['downloads'])===1,'Restored valid permission becomes available');
$orderA->update_status('refunded');
verify(api('downloads',2)->get_data()['downloads']===array(),'Refunded order excluded by Woo download permission engine');
$orderA->update_status('completed');
verify(count(api('downloads',2)->get_data()['downloads'])===1,'Completed order with valid permission available again');
$permission->delete(true);
verify(api('downloads',2)->get_data()['downloads']===array(),'Deleted/revoked permission excluded');
verify(count(api('downloads',3)->get_data()['downloads'])===1,'A permission changes do not affect B download');
$pending=order_for(2,$product,'pending');
verify(api('downloads',2)->get_data()['downloads']===array(),'Pending unpaid order grants no available download');

// Real capability and check_admin_referer, with wp_die captured before PHP exit.
class IntegrationDenied extends RuntimeException {}
add_filter('wp_die_handler',function(){ return function($message){ throw new IntegrationDenied(is_string($message)?strip_tags($message):'Denied'); }; });
$before=get_option('aisa_settings',array()); wp_set_current_user(2); $_POST=array('enabled'=>1); $_REQUEST=array();
try { AISA_Agent::save(); verify(false,'Customer cannot save admin settings'); } catch (IntegrationDenied $e) { verify(true,'Real admin capability check rejects customer'); }
wp_set_current_user(1); $_REQUEST['_wpnonce']='invalid';
try { AISA_Agent::save(); verify(false,'Invalid admin nonce cannot save'); } catch (IntegrationDenied $e) { verify(true,'Real administrator nonce verification rejects invalid nonce'); }
verify(get_option('aisa_settings',array())===$before,'Denied admin saves leave real settings unchanged');

// Real source storage, review CSRF validation and public retrieval of approved facts.
// Exercise the explicit manual policy here; fresh automatic website policy has its own native profile.
$manualPolicy=AISA_Agent::settings();$oldPublicPolicy=$manualPolicy;$manualPolicy['public_sync']=0;$manualPolicy['public_reference']=0;update_option(AISA_Agent::OPTION,$manualPolicy,false);AISA_Public_Sync::configure($oldPublicPolicy,$manualPolicy);
class IntegrationRedirected extends RuntimeException {}
add_filter('wp_redirect',function(){ throw new IntegrationRedirected(); });
function agent_method($method,...$args) { return (new ReflectionMethod('AISA_Agent',$method))->invoke(null,...$args); }
function approve_source($id,$decision='approve') {
    wp_set_current_user(1); $_POST=array('id'=>$id,'decision'=>$decision,'review_hash'=>agent_method('review_hash',get_post($id))); $_REQUEST['_wpnonce']=wp_create_nonce('aisa_admin');
    try { AISA_Agent::review(); } catch (IntegrationRedirected $e) { return; }
    throw new RuntimeException('Review did not complete');
}
wp_set_current_user(1);
$page=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Quartzpolicy shipping','post_content'=>'Quartzpolicy ships in two days.'));
$record=agent_method('source_id',$page);
verify($record>0 && agent_method('knowledge','Quartzpolicy')===array(),'Real save hook indexes but does not approve');
approve_source($record); wp_set_current_user(0);
verify(count(agent_method('knowledge','Quartzpolicy'))===1,'Guest support can retrieve an approved public fact stored privately');
wp_set_current_user(2); verify(count(agent_method('knowledge','Quartzpolicy'))===1,'Customer retrieves the same approved public fact');
approve_source($record,'revoke'); wp_set_current_user(0);
verify(agent_method('knowledge','Quartzpolicy')===array(),'Revoked source excluded for guests');
approve_source($record); wp_update_post(array('ID'=>$page,'post_content'=>'Quartzpolicy changed after review.')); wp_set_current_user(0);
verify(agent_method('knowledge','Quartzpolicy')===array(),'Real save update invalidates prior approval');

// Keep a separate permitted order for fresh-request tests of the actual file handler.
wp_set_current_user(1); $endpointOrder=order_for(2,$product); $endpointPermissions=$store->get_downloads(array('order_id'=>$endpointOrder->get_id()));
update_option('woocommerce_downloads_require_login','yes'); update_option('woocommerce_file_download_method','force');
wp_set_current_user(2); $available=wc_get_customer_available_downloads(2); $endpointUrl='';
foreach ($available as $download) { if ((int)$download['order_id']===$endpointOrder->get_id()) { $endpointUrl=$download['download_url']; break; } }
verify($endpointUrl!=='' && count($endpointPermissions)===1,'Endpoint fixture has a real permitted download');
file_put_contents('/download-fixture.json',wp_json_encode(array('url'=>$endpointUrl,'order'=>$endpointOrder->get_id(),'permission'=>$endpointPermissions[0]->get_id())));
$beforeMemoryChecks=$checks; require __DIR__.'/memory-test.php'; $memoryChecks=$checks-$beforeMemoryChecks;
$beforeTransportChecks=$checks; if (version_compare(AISA_VERSION,'0.1.5','>=')) { require __DIR__.'/transport-test.php'; } $transportChecks=$checks-$beforeTransportChecks;
$beforeInboxChecks=$checks; if (version_compare(AISA_VERSION,'0.1.6','>=')) { require __DIR__.'/inbox-test.php'; } $inboxChecks=$checks-$beforeInboxChecks;
$beforeCustomerChecks=$checks; if (version_compare(AISA_VERSION,'0.1.7','>=')) { require __DIR__.'/customer-tickets.php'; } $customerChecks=$checks-$beforeCustomerChecks;
echo wp_json_encode(array('status'=>'PASS','checks'=>$checks,'memory_checks'=>$memoryChecks,'transport_checks'=>$transportChecks,'inbox_checks'=>$inboxChecks,'customer_ticket_checks'=>$customerChecks,'provider_transport'=>'synthetic_pre_http_request','mcp_protocol_peer'=>getenv('AISA_DEFER_MCP')==='1'?'deferred':'handwritten_fixture','notification_delivery_verified'=>false,'plugin_version'=>AISA_VERSION,'wordpress'=>$wp_version,'woocommerce'=>WC_VERSION,'php'=>PHP_VERSION,'database'=>DB_ENGINE,'order_storage'=>\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'hpos':'cpt','principal'=>'synthetic-set-current-user','download_login_required'=>get_option('woocommerce_downloads_require_login')==='yes','network_adapter_enabled'=>false,'login_credentials_created'=>false,'provider_calls_verified'=>false,'mcp_interoperability_verified'=>false));
