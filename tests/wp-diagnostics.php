<?php
// Real WordPress rendering, synthetic preset administrator; no signed login or external test.
require __DIR__.'/wp-load.php';
$checks=0; $transports=0; wp_set_current_user(1); $_GET['view']='diagnostics';
add_filter('pre_http_request',function($pre) use (&$transports) { $transports++; return $pre; },PHP_INT_MAX);
add_filter('pre_wp_mail',function($pre) use (&$transports) { $transports++; return $pre; },PHP_INT_MAX);
function dcheck($ok,$label) { global $checks; $checks++; if (!$ok) { throw new RuntimeException('Diagnostics assertion: '.$label); } }
function dstatus($html,$key) { $d=new DOMDocument(); $old=libxml_use_internal_errors(true); $d->loadHTML('<meta charset="utf-8">'.$html); libxml_clear_errors();libxml_use_internal_errors($old);return trim((new DOMXPath($d))->query('//*[@data-aisa-check="'.$key.'"]//span[contains(@class,"aisa-badge")]')->item(0)->textContent); }
function drender() {
    global $transports;
    $before=array(get_option('aisa_settings'),get_option('wp_user_roles'),$transports);
    ob_start();AISA_Agent::admin();$html=ob_get_clean();
    dcheck(array(get_option('aisa_settings'),get_option('wp_user_roles'),$transports)===$before,'rendering diagnostics changes no settings/roles/transports');return $html;
}
function dcipher($plain) { return (new ReflectionMethod('AISA_Agent','encrypt'))->invoke(null,$plain); }
$base=AISA_Agent::defaults();update_option('aisa_settings',$base);
$html=drender();dcheck(dstatus($html,'provider')==='Credential missing or unreadable'&&dstatus($html,'mcp')==='Disabled','automatic official preset without a key does not claim readiness');
$manual=$base;$manual['model_mode']='manual';update_option('aisa_settings',$manual);
dcheck(dstatus(drender(),'provider')==='Needs setup','explicit empty manual model needs setup');
dcheck(str_contains($html,'Untested on this site')&&str_contains($html,'does not resolve DNS'),'real dashboard preserves verification boundary');
foreach(array('local','compatible') as $provider) {
    $s=array_merge($base,array('provider'=>$provider,'model_mode'=>'manual','model'=>'synthetic-model','endpoint'=>'https://gateway.fixture.invalid/chat'));update_option('aisa_settings',$s);
    $html=drender();dcheck(dstatus($html,'provider')==='No API key stored · gateway auth unverified','real optional-key adapter status '.$provider);
    dcheck(dstatus($html,'endpoint')==='URL shape set · untested','real endpoint shape observation '.$provider);
}
foreach(array('http://gateway.fixture.invalid/chat'=>'HTTPS required','https://127.0.0.1/chat'=>'Local/private address warning','https://[::1]/chat'=>'Local/private address warning','https://user:synthetic@gateway.fixture.invalid/chat'=>'URL needs review') as $url=>$label) {
    $s['endpoint']=$url;update_option('aisa_settings',$s);dcheck(dstatus(drender(),'endpoint')===$label,'real WP URL parsing diagnostic '.$label);
}
$s['endpoint']='https://gateway.fixture.invalid/chat';$s['secret']='unreadable-synthetic-cipher';$s['telegram_secret']=dcipher('synthetic-malformed-token');$s['telegram_chat']='synthetic-chat';$s['webhook_secret']=dcipher('http://relay.fixture.invalid/private-synthetic-path');update_option('aisa_settings',$s);
$s['secret_scope']=AISA_Provider::scope($s);update_option('aisa_settings',$s);
$html=drender();dcheck(dstatus($html,'provider')==='Credential missing or unreadable','real unreadable model credential diagnostic');
dcheck(dstatus($html,'telegram')==='Token format needs review'&&dstatus($html,'relay')==='HTTPS required','real malformed notification configuration diagnostics');
foreach(array('synthetic-malformed-token','private-synthetic-path',$s['secret'],$s['telegram_secret'],$s['webhook_secret']) as $secret) { dcheck(!str_contains($html,$secret),'diagnostics never reveal fixture secret/ciphertext'); }
$s['telegram_secret']=dcipher('0:synthetic_noncredential');update_option('aisa_settings',$s);dcheck(dstatus(drender(),'telegram')==='Configured · untested','format observation never implies Telegram delivery');
wp_set_current_user(2);ob_start();AISA_Agent::admin();$unauthorized=ob_get_clean();dcheck($unauthorized===''&&$transports===0,'customer receives no admin diagnostic or external call');
foreach(array(1,2,3) as $uid) { dcheck(get_userdata($uid)->user_pass==='*'&&!WP_Session_Tokens::get_instance($uid)->get_all()&&!get_user_meta($uid,'_application_passwords',true),'no usable credentials/sessions created'); }
echo wp_json_encode(array('status'=>'PASS','checks'=>$checks,'plugin_version'=>AISA_VERSION,'real_login_verified'=>false,'principal'=>'preset synthetic users; no cookies','external_calls'=>0,'credentials_created'=>false));
