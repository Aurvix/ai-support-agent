<?php
// A new real WordPress request for each case. The synthetic URL never leaves PHP memory.
require __DIR__.'/wp-load.php';
$case=getenv('AISA_DOWNLOAD_CASE'); $fixture=json_decode(file_get_contents('/download-fixture.json'),true);
$order=wc_get_order($fixture['order']); $permission=new WC_Customer_Download($fixture['permission']);
if ($case==='expired') { $permission->set_access_expires(time()-DAY_IN_SECONDS); }
else { $permission->set_access_expires(null); }
$permission->set_downloads_remaining($case==='exhausted'?'0':''); $permission->save();
$order->update_status($case==='refunded'?'refunded':($case==='pending'?'pending':'completed'));
update_option('woocommerce_downloads_require_login',$case==='bearer-policy'?'no':'yes');
if ($case==='revoked') { $permission->delete(true); }
wp_set_current_user(in_array($case,array('wrong-owner','bearer-policy'),true)?3:($case==='anonymous'?0:2));
parse_str(wp_parse_url($fixture['url'],PHP_URL_QUERY),$_GET);
if ($case==='forged-order') { $_GET['order']='synthetic-invalid-order-key'; }
if ($case==='forged-email') { unset($_GET['email']); $_GET['uid']=hash('sha256','different@example.test'); }
if ($case==='array-argument') { $_GET['key']=array('synthetic-file'); }
class DownloadDenied extends RuntimeException { public $status; public function __construct($status) { $this->status=$status; } }
$deny=function(){ return function($message,$title='',$args=array()) { throw new DownloadDenied(is_array($args)?($args['response']??500):$args); }; };
add_filter('wp_die_handler',$deny); add_filter('wp_die_ajax_handler',$deny);
// Capture a login redirect without following it. No alternate file handler is installed.
add_filter('wp_redirect',function(){ throw new DownloadDenied(302); });
try { WC_Download_Handler::download_product(); }
catch (DownloadDenied $e) { echo wp_json_encode(array('case'=>$case,'denied'=>true,'status'=>$e->status)); }
