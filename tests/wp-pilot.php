<?php
// Bounded first-use flow, real WP/Woo with synthetic preset principals and intercepted model transport.
require __DIR__.'/wp-load.php';require_once ABSPATH.'wp-admin/includes/plugin.php';
$checks=0;function pcheck($ok,$label){global $checks;$checks++;if(!$ok)throw new RuntimeException('Pilot assertion: '.$label);}
function pm($name,...$args){return (new ReflectionMethod('AISA_Agent',$name))->invoke(null,...$args);}
function pconfig(){AISA_Agent::assets();$before=wp_scripts()->get_data('aisa-widget','before');$raw=end($before);preg_match('/window\.AISA_CONFIG=(.*);/s',$raw,$m);return json_decode($m[1],true);}
function pr($route,$uid,$body=array(),$validNonce=true){
 global $wp_rest_auth_cookie;wp_set_current_user($uid);$wp_rest_auth_cookie=$uid>0;unset($_REQUEST['_wpnonce'],$_SERVER['HTTP_X_WP_NONCE']);
 if($uid)$_SERVER['HTTP_X_WP_NONCE']=$validNonce?wp_create_nonce('wp_rest'):'synthetic-invalid';
 $method=in_array($route,array('orders','downloads'),true)?'GET':'POST';$r=new WP_REST_Request($method,'/aisa/v1/'.$route);
 if($method==='POST'){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}else{$r->set_query_params($body);}
 $server=rest_get_server();$auth=$server->check_authentication();return is_wp_error($auth)?rest_convert_error_to_response($auth):$server->dispatch($r);
}
if(getenv('AISA_PILOT_PHASE')==='no-woo'){
 pcheck(!function_exists('wc_get_orders'),'fresh no-Woo bootstrap has no commerce functions');
 $c=pconfig();pcheck($c['commerce']===false,'widget flags absent optional commerce');
 pcheck(AISA_Agent::orders()->get_data()['orders']===array()&&AISA_Agent::downloads()->get_data()['downloads']===array(),'optional commerce handlers remain graceful');
 foreach(array(1,2,3)as$uid)pcheck(get_userdata($uid)->user_pass==='*'&&!WP_Session_Tokens::get_instance($uid)->get_all()&&!get_user_meta($uid,'_application_passwords',true),'no login credentials/sessions');
 echo wp_json_encode(array('status'=>'PASS','checks'=>$checks,'phase'=>'no-woo'));return;
}
$roles=get_option('wp_user_roles');$downloadPolicy=get_option('woocommerce_downloads_require_login');$calls=0;$mail=0;$prompts=array();$mode='ok';
pcheck(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()===(getenv('AISA_PILOT_STORAGE')==='hpos'),'actual requested Woo storage mode');
add_filter('pre_wp_mail',function($pre)use(&$mail){$mail++;return $pre;},PHP_INT_MAX);
add_filter('pre_http_request',function($pre,$args,$url)use(&$calls,&$prompts,&$mode){
 if($url!=='https://pilot.fixture.invalid/chat')return $pre;$calls++;$prompts[]=$args['body'];
 if($mode==='fail')return new WP_Error('fixture','private-upstream-detail');
 if($mode==='revoke'){delete_post_meta((int)get_option('aisa_pilot_record'),'aisa_approved_hash');}
 return array('headers'=>array(),'response'=>array('code'=>200,'message'=>'Synthetic'),'body'=>wp_json_encode(array('choices'=>array(array('message'=>array('content'=>'Synthetic public FAQ answer. नमस्ते।'),'finish_reason'=>'stop')))),'cookies'=>array());
},PHP_INT_MAX,3);
pcheck(AISA_Agent::settings()['enabled']===0,'first activation leaves customer chat disabled');
pcheck(AISA_Agent::settings()['mcp']===0&&AISA_Agent::settings()['memory']===0&&AISA_Agent::settings()['learning']===0&&AISA_Agent::settings()['customer_tickets']===0,'advanced/pilot-sensitive options retain default-off values; no MCP calls');
AISA_Agent::assets();pcheck(!wp_script_is('aisa-widget','enqueued'),'disabled chat enqueues no widget');
$s=AISA_Agent::defaults();$s['enabled']=1;$s['support_email']='support@example.test';update_option('aisa_settings',$s);
$c=pconfig();pcheck($c['commerce']===true&&$c['loggedIn']===false&&$c['nonce']==='','guest config contains commerce availability but no account nonce');
foreach(array('secret','telegram_secret','webhook_secret','instructions')as$key)pcheck(!array_key_exists($key,$c),'public config omits '.$key);
$scope=$c['scope'];$context=pr('context',0,array('scope'=>$scope));pcheck($context->get_status()===200,'model-unconfigured site still permits session check');
$missing=pr('chat',0,array('message'=>'Pilotquartz policy','scope'=>$scope));pcheck($missing->get_status()===503&&$calls===0,'missing model gives setup error with zero model attempts');
// Real core indexing/explicit review; redirect is intercepted, no signed login is established.
$page=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Pilotquartz public help','post_content'=>'Pilotquartz public FAQ fact.'));
$id=pm('source_id',$page);pcheck($id>0&&pm('knowledge','Pilotquartz')===array(),'indexing creates an unapproved source excluded from chat');
wp_set_current_user(1);$_POST=array('id'=>$id,'decision'=>'approve','review_hash'=>pm('review_hash',get_post($id)));$_REQUEST['_wpnonce']=wp_create_nonce('aisa_admin');
add_filter('wp_redirect',function($url){throw new RuntimeException('pilot-local-redirect');});
try{AISA_Agent::review();}catch(RuntimeException $e){if($e->getMessage()!=='pilot-local-redirect')throw $e;}
pcheck(count(pm('knowledge','Pilotquartz'))===1,'explicit native admin review enables current public source');
update_option('aisa_pilot_record',$id);$s['provider']='local';$s['endpoint']='https://pilot.fixture.invalid/chat';$s['model']='synthetic-pilot';$s['model_mode']='manual';update_option('aisa_settings',$s);
$reply=pr('chat',0,array('message'=>'Pilotquartz policy in Hindi','scope'=>$scope));pcheck($reply->get_status()===200&&str_contains($reply->get_data()['reply'],'नमस्ते'),'reviewed chat route handles intercepted UTF8 answer');
pcheck(count($reply->get_data()['sources'])===1&&str_contains(end($prompts),'Pilotquartz public FAQ fact.'),'model grounding contains approved public fact and response attribution');
$mode='revoke';$stale=pr('chat',0,array('message'=>'Pilotquartz policy','scope'=>$scope));pcheck($stale->get_status()===409&&$stale->get_data()['code']==='knowledge_changed','approval change during model work rejects answer');
$mode='fail';$failed=pr('chat',0,array('message'=>'Pilotquartz policy','scope'=>$scope));pcheck($failed->get_status()===503&&!str_contains(wp_json_encode($failed->get_data()),'private-upstream-detail'),'provider fixture failure is redacted');
$before=$calls;$s['model']='';update_option('aisa_settings',$s);
$product=new WC_Product_Simple();$product->set_name('Synthetic pilot download');$product->set_status('publish');$product->set_regular_price('5');$product->set_downloadable(true);$product->set_virtual(true);$product->set_download_limit(-1);$product->set_download_expiry(-1);
$dir=wp_upload_dir()['basedir'].'/woocommerce_uploads';wp_mkdir_p($dir);file_put_contents($dir.'/pilot.txt','Synthetic public file only.');
$file=new WC_Product_Download();$file->set_id('pilot-file');$file->set_name('Synthetic pilot file');$file->set_file($dir.'/pilot.txt');$product->set_downloads(array($file));$product->save();$orders=array();
foreach(array(2,3)as$uid){$o=wc_create_order(array('customer_id'=>$uid));$o->set_billing_email(get_userdata($uid)->user_email);$o->add_product($product,1);$o->calculate_totals();$o->set_status('completed');$o->save();wc_downloadable_product_permissions($o->get_id());$orders[$uid]=$o->get_id();}
foreach(array(2,3)as$uid){
 $own=pr('orders',$uid,array('customer_id'=>$uid===2?3:2))->get_data()['orders'];pcheck(count($own)===1&&(string)$own[0]['number']===(string)$orders[$uid],'model missing still returns only preset owner orders');
 $downloadResponse=pr('downloads',$uid,array('customer_id'=>$uid===2?3:2))->get_data();$dl=$downloadResponse['downloads'];pcheck(count($dl)===1,'native Woo available-download query works without model');
 pcheck($downloadResponse['account_url']===wc_get_account_endpoint_url('downloads') && $dl[0]['url']===$downloadResponse['account_url'],'each owner receives native account navigation instead of a file URL');
 pcheck(!str_contains(wp_json_encode($downloadResponse),'download_file=') && !str_contains(wp_json_encode($downloadResponse),'woocommerce_uploads') && !str_contains(wp_json_encode($downloadResponse),'order_key'),'REST account metadata contains no signed file URL, file path or order key');
}
$myAccount=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Synthetic account','post_name'=>'customer-account'));
update_option('woocommerce_myaccount_page_id',$myAccount);update_option('woocommerce_myaccount_downloads_endpoint','my-files');
WC()->query->init_query_vars(); // Simulate the next native request after changing the fixture endpoint.
$nativeAccount=pr('downloads',2)->get_data();
pcheck($nativeAccount['account_url']===wc_get_account_endpoint_url('downloads') && str_contains($nativeAccount['account_url'],'my-files'),'native custom account page/endpoint honored without hardcoded /my-account/ path');
$fileQuery=wc_get_customer_available_downloads(2);$beforeCount=count($fileQuery);
foreach(array('https://foreign.invalid/files','https://user:secret@aisa.local.test/files','https://aisa.local.test/?download_file=secret','https://aisa.local.test/?key=secret','https://aisa.local.test/files#secret','http://aisa.local.test/files')as$bad){
 $filter=function($url,$endpoint)use($bad){return $endpoint==='my-files'?$bad:$url;};add_filter('woocommerce_get_endpoint_url',$filter,10,2);
 $rejected=pr('downloads',2)->get_data();pcheck($rejected['account_url']==='' && $rejected['downloads'][0]['url']==='','foreign/private/credential/fragment/insecure filtered account destination rejected');
 remove_filter('woocommerce_get_endpoint_url',$filter,10);
}
pcheck(count(wc_get_customer_available_downloads(2))===$beforeCount,'account navigation does not consume or revoke Woo download permissions');
pcheck(pr('orders',0)->get_status()===401&&pr('downloads',0)->get_status()===401,'guest cannot read account results');
pcheck(pr('orders',2,array(),false)->get_status()===403,'native invalid account nonce remains denied');
$handoff=pr('handoff',0);pcheck($handoff->get_status()===200&&$calls===$before&&$mail===0,'human guidance works without model or configured notification send');
pcheck(get_option('wp_user_roles')===$roles&&get_option('woocommerce_downloads_require_login')===$downloadPolicy,'roles and native download security policy unchanged');
foreach(array(1,2,3)as$uid)pcheck(get_userdata($uid)->user_pass==='*'&&!WP_Session_Tokens::get_instance($uid)->get_all()&&!get_user_meta($uid,'_application_passwords',true),'no login credentials/sessions');
deactivate_plugins('woocommerce/woocommerce.php');
echo wp_json_encode(array('status'=>'PASS','checks'=>$checks,'phase'=>'first-use','plugin_version'=>AISA_VERSION,'provider'=>'intercepted synthetic response only','actual_external_calls'=>0,'real_login_verified'=>false,'mcp'=>'deferred, no MCP request invoked'));
