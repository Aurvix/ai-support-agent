<?php
$savedCustomerSettings=get_option('aisa_settings');
verify(post_type_exists('aisa_request') && !get_post_type_object('aisa_request')->public && !get_post_type_object('aisa_request')->show_in_rest && !get_post_type_object('aisa_request')->can_export,'Actual account request type private and absent from public REST/export');
verify(has_action('admin_post_aisa_request')!==false && has_action('admin_post_nopriv_aisa_request')===false,'Review action authenticated only');
function real_ticket_api($op,$params,$nonce='valid') {
    global $wp_rest_auth_cookie;
    $uid=get_current_user_id(); $wp_rest_auth_cookie=$uid>0;
    unset($_REQUEST['_wpnonce'],$_SERVER['HTTP_X_WP_NONCE']);
    $request=new WP_REST_Request('POST','/aisa/v1/tickets/'.$op); $request->set_header('Content-Type','application/json'); $request->set_body(wp_json_encode($params));
    if ($nonce!=='missing') {
        $value=$nonce==='valid'?wp_create_nonce('wp_rest'):($nonce==='expired'?substr(wp_hash((wp_nonce_tick('wp_rest')-2).'|wp_rest|'.$uid.'|'.wp_get_session_token(),'nonce'),-12,10):'invalid-synthetic-nonce');
        $request->set_header('X-WP-Nonce',$value); $_SERVER['HTTP_X_WP_NONCE']=$value;
    }
    $server=rest_get_server(); $auth=$server->check_authentication();
    $response=is_wp_error($auth)?rest_convert_error_to_response($auth):$server->dispatch($request);
    $response=apply_filters('rest_post_dispatch',$response,$server,$request);
    wp_set_current_user($uid);
    return array('status'=>$response->get_status(),'data'=>$response->get_data(),'headers'=>$response->get_headers());
}
require __DIR__.'/customer-ticket-contract.php';
customer_ticket_contract('verify',function($uid,$admin) {
    if ($uid===3) { (new WP_User(3))->set_role($admin?'administrator':'customer'); }
    wp_set_current_user($uid);
},'real_ticket_api');
(new WP_User(3))->set_role('customer'); wp_set_current_user(2);
$settings=AISA_Agent::settings(); $settings['customer_tickets']=1; update_option('aisa_settings',$settings);
$scope=AISA_Memory::scope(); $body=array('scope'=>$scope,'topic'=>'downloads','confirm'=>true,'request_id'=>str_repeat('d',32));
$created=real_ticket_api('submit',$body); $id=$created['data']['ticket']['id'];
verify(!current_user_can('edit_post',$id) && !current_user_can('delete_post',$id),'Generic WordPress customer edit/delete capability denied');
verify(real_ticket_api('status',array('scope'=>$scope),'invalid')['headers']['Cache-Control']==='private, no-store','Actual auth-error ticket response also no-store');
wp_set_current_user(0); verify(real_ticket_api('status',array('scope'=>$scope))['headers']['Cache-Control']==='private, no-store','Anonymous ticket error not cacheable');
wp_set_current_user(2); $request=new WP_REST_Request('GET','/aisa/v1/tickets/status');
verify(rest_get_server()->dispatch($request)->get_status()===404,'Ticket status does not accept GET/query-string private parameters');
wp_set_current_user(1); $_POST=array('operation'=>'claim','id'=>(string)$id,'revision'=>'1'); $_REQUEST=array('_wpnonce'=>'invalid');
verify(!current_user_can('edit_post',$id) && !current_user_can('delete_post',$id) && !current_user_can(get_post_type_object('aisa_request')->cap->create_posts),'Generic WordPress admin post handlers cannot bypass controlled ticket review');
try { do_action('admin_post_aisa_request'); verify(false,'Invalid reviewer nonce must fail'); } catch (IntegrationDenied $e) { verify(true,'Actual reviewer nonce failure'); }
$_REQUEST['_wpnonce']=wp_create_nonce('aisa_admin');
try { do_action('admin_post_aisa_request'); verify(false,'Claim should redirect'); } catch (IntegrationRedirected $e) { verify(json_decode(get_post($id)->post_content,true)['reviewer']===1,'Actual admin POST claims account request'); }
wp_set_current_user(2); $_POST=array('operation'=>'status','id'=>(string)$id,'revision'=>'2','status'=>'resolved'); $_REQUEST['_wpnonce']=wp_create_nonce('aisa_admin');
try { do_action('admin_post_aisa_request'); verify(false,'Customer admin action must fail'); } catch (IntegrationDenied $e) { verify(true,'Actual customer cannot review status through admin POST'); }
wp_set_current_user(1);
$previousView=$_GET; $_GET=array('view'=>'inbox','inbox_tab'=>'handoff'); ob_start(); AISA_Agent::admin(); $html=ob_get_clean(); $_GET=$previousView;
verify(str_contains($html,'Account-linked support requests') && str_contains($html,'Update review status'),'Actual admin renders claimed account request');
verify(!str_contains(wp_json_encode(agent_method('knowledge','Account support')),'Account support:') && !str_contains(wp_json_encode(agent_method('catalog','Account support')),'Account support:'),'Account tickets excluded from approved retrieval/catalog');
$audit=get_posts(array('post_type'=>'aisa_audit','post_status'=>'private','posts_per_page'=>100));
verify(!str_contains(wp_json_encode($audit),'RAW_TICKET_CANARY') && !str_contains(wp_json_encode($audit),str_repeat('d',32)),'Actual audit excludes raw ticket payload and submission ID');
$bad=real_ticket_api('submit',array('scope'=>AISA_Memory::scope(),'topic'=>'general','confirm'=>true,'request_id'=>str_repeat('f',32),'message'=>'RAW_TICKET_CANARY'));
verify($bad['status']===400,'Actual REST parser rejects transcript field');
foreach (get_posts(array('post_type'=>'aisa_request','post_status'=>'any','posts_per_page'=>101)) as $p) { wp_delete_post($p->ID,true); }
delete_option(AISA_Handoff::RATES); update_option('aisa_settings',$savedCustomerSettings);
