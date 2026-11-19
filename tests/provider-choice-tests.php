<?php
// Synthetic fixtures only. Reuses the native capability/nonce/HTTP boundary from wp-admin-training.php.
$providerSettingsBefore=get_option('aisa_settings');
foreach(array('openai','gemini','nvidia','groq')as$providerId){
 $list=AISA_Provider::models($providerId);tcheck(count($list)>=3,'selectable provider names: '.$providerId);
 foreach($list as$modelId=>$info)tcheck(is_string($info['name'])&&$info['name']!==''&&is_string($info['availability']),'named model with honest availability: '.$providerId);
}
tcheck(AISA_Provider::models('compatible')===array()&&AISA_Provider::models('local')===array(),'no invented universal gateway catalog');
tcheck(str_contains(AISA_Provider::availability('groq','llama-3.3-70b-versatile'),'Enterprise'),'Groq access limitation shown');
tcheck(str_contains(AISA_Provider::availability('nvidia','meta/llama-3.3-70b-instruct'),'deprecated'),'NVIDIA free endpoint limitation shown');
tcheck(str_contains(AISA_Provider::availability('openai','gpt-4.1-mini'),'Deprecated'),'legacy OpenAI default not marketed as current');
$storedKey=AISA_Agent::settings()['secret'];
savescope('providers',array('provider'=>'gemini','model_choice'=>'gemini-3.5-flash-lite','daily_limit'=>200,'hourly_limit'=>20));
$s=AISA_Agent::settings();tcheck($s['model_mode']==='preset'&&AISA_Provider::resolve($s)['model']==='gemini-3.5-flash-lite','selected Gemini ID saved and resolved');tcheck($s['secret']===$storedKey&&AISA_Provider::matches($s),'model choice preserves ciphertext and destination binding');
$beforeSettings=get_option('aisa_settings');postdata(array('aisa_view'=>'providers','settings_revision'=>AISA_Agent::revision(),'provider'=>'gemini','model_choice'=>'gpt-6-luna'));runop(array('AISA_Agent','save'),'denied');tcheck(get_option('aisa_settings')===$beforeSettings,'cross-provider option denied without changing settings');
foreach(array(array('x'),str_repeat('x',201),'https://untrusted.fixture.invalid/model','bad model',"bad\nmodel",'')as$invalid){tcheck(is_wp_error(AISA_Provider::choose('compatible','__manual__',$invalid)),'manual model bounds/shape reject invalid value');}
tcheck(AISA_Provider::choose('local','__manual__','llama3.2:3b')['model']==='llama3.2:3b','gateway manual ID remains usable');
tcheck(is_wp_error(AISA_Provider::choose('local','__auto__','')),'gateway cannot claim automatic catalog');
tcheck(is_wp_error(AISA_Provider::choose('gemini',array('gemini-3.8-flash'),'')),'array model choice rejected');
savescope('providers',array('provider'=>'gemini','model_choice'=>'__auto__','daily_limit'=>200,'hourly_limit'=>20));
$s=AISA_Agent::settings();tcheck(AISA_Provider::resolve($s)['model']==='gemini-3.1-flash-lite','existing automatic default preserved');
$probePost=function($variant='standard',$extra=array()){postdata(array_merge(array('settings_revision'=>AISA_Agent::revision(),'probe_variant'=>$variant),$extra));};
$callCount=count($calls);wp_set_current_user(2);$probePost();runop(array('AISA_Agent','provider_probe'),'denied');wp_set_current_user(1);tcheck(count($calls)===$callCount,'customer cannot trigger paid provider check');
$probePost();$_REQUEST['_wpnonce']='invalid';runop(array('AISA_Agent','provider_probe'),'denied');tcheck(count($calls)===$callCount,'invalid probe nonce makes no request');
$probePost('standard',array('settings_revision'=>'stale'));runop(array('AISA_Agent','provider_probe'),'denied');tcheck(count($calls)===$callCount,'stale probe makes no request');
$probePost('unsupported');runop(array('AISA_Agent','provider_probe'),'denied');tcheck(count($calls)===$callCount,'invalid diagnostic variant makes no request');
savescope('providers',array('provider'=>'compatible','model_mode'=>'manual','model'=>'fixture-id','endpoint'=>'https://untrusted.fixture.invalid/chat'));
$probePost();runop(array('AISA_Agent','provider_probe'),'denied');tcheck(count($calls)===$callCount,'probe never calls a custom/untrusted endpoint');
update_option('aisa_settings',$providerSettingsBefore);$s=AISA_Agent::settings();delete_transient(AISA_Provider::PROBE_LIMIT);$mode='ok';$beforeSettings=get_option('aisa_settings');
$probePost('standard',array('provider'=>'compatible','endpoint'=>'https://untrusted.fixture.invalid/chat','model'=>'not-the-saved-model','messages'=>'private-order-link-canary'));
runop(array('AISA_Agent','provider_probe'));$last=end($calls);$testBody=json_decode($last['args']['body'],true);
tcheck(count($calls)===$callCount+1,'one explicit probe makes exactly one intercepted request');
tcheck($last['url']==='https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'&&$testBody['model']==='gemini-3.1-flash-lite','probe uses saved model and trusted official endpoint');
tcheck($testBody['stream']===false&&$testBody['max_tokens']===128&&$last['args']['timeout']===20,'synthetic output/time/non-streaming bounds');
tcheck($testBody['reasoning_effort']==='minimal','standard saved Gemini options retained');
tcheck(count($testBody['messages'])===2&&$testBody['messages'][1]['content']==='Reply with AISA_OK.','probe sends only fixed synthetic prompt');
tcheck(!str_contains(wp_json_encode($testBody),'private-order-link-canary')&&!str_contains(wp_json_encode($testBody),'Administrator instructions')&&!str_contains(wp_json_encode($testBody),'Refundquartz'),'probe cannot include customer/admin/knowledge context');
tcheck(get_option('aisa_settings')===$beforeSettings,'probe writes no settings or credentials');
$observed=AISA_Provider::observation($s);tcheck($observed['category']==='ok'&&$observed['http']===200&&$observed['synthetic'],'complete synthetic response recorded truthfully');
tcheck(!array_key_exists('signature',$observed)&&!str_contains(wp_json_encode($observed),'synthetic-gemini'),'observation exposes no binding or key');
$callCount=count($calls);$probePost();$denied=runop(array('AISA_Agent','provider_probe'),'denied');tcheck(count($calls)===$callCount&&str_contains($denied,'one minute'),'sequential cooldown prevents a second request');
delete_transient(AISA_Provider::PROBE_LIMIT);$probePost('compatibility');runop(array('AISA_Agent','provider_probe'));$compat=json_decode(end($calls)['args']['body'],true);tcheck(!isset($compat['reasoning_effort'])&&$compat['model']===$testBody['model'],'compatibility check only omits optional reasoning, not provider/model');
$overrideCases=array(400=>'http',401=>'authentication',403=>'authentication',404=>'model_or_endpoint_unavailable',429=>'quota',500=>'upstream',503=>'upstream');
foreach($overrideCases as$status=>$category){
 $responseOverride=array('headers'=>array('private'=>'upstream-header-canary'),'response'=>array('code'=>$status),'body'=>wp_json_encode(array('error'=>array('status'=>'INTERNAL','message'=>'upstream-body-key-canary'))),'cookies'=>array());
 $n=count($calls);$result=tm('complete',array(array('role'=>'user','content'=>'Synthetic only')));$o=AISA_Provider::observation($s);
 tcheck(is_wp_error($result)&&count($calls)===$n+1,'error is one request with no retry: '.$status);
 tcheck($o['http']===$status&&$o['category']===$category&&$o['api']==='INTERNAL','sanitized status/category retained: '.$status);
 tcheck(!str_contains(wp_json_encode($o),'canary')&&!str_contains($result->get_error_message(),'canary'),'raw upstream data absent: '.$status);
}
foreach(array('not-json','null','[]','{"error":"private-key-canary"}','{"error":{"status":"private-key-canary"}}')as$body)tcheck(AISA_Provider::api_status(array('body'=>$body))==='','malformed/unlisted API status omitted');
$responseOverride=null;$mode='timeout';tm('complete',array());tcheck(AISA_Provider::observation($s)['category']==='transport'&&AISA_Provider::observation($s)['http']===0,'transport failure has no invented HTTP status');$mode='ok';
AISA_Provider::record($s,'ok',200);$other=$s;$other['model_mode']='preset';$other['model']='gemini-3.5-flash-lite';tcheck(AISA_Provider::observation($other)===null,'another model cannot inherit a successful test');
$other=$s;$other['secret']=tm('encrypt','other-disabled-synthetic-secret');tcheck(AISA_Provider::observation($other)===null,'another credential cannot inherit a successful test');
$old=get_transient(AISA_Provider::OBSERVATION);$old['at']=time()-3601;set_transient(AISA_Provider::OBSERVATION,$old,HOUR_IN_SECONDS);tcheck(AISA_Provider::observation($s)===null,'stale diagnostic result expires');
AISA_Provider::record($s,'raw-key-canary',800,'raw-key-canary');$o=AISA_Provider::observation($s);tcheck($o['category']==='http'&&$o['http']===0&&$o['api']==='','diagnostic fields are allowlisted');
$stored=get_transient(AISA_Provider::OBSERVATION);$stored['category']='raw-key-canary';$stored['api']='raw-key-canary';$stored['http']=array('raw-key-canary');set_transient(AISA_Provider::OBSERVATION,$stored,HOUR_IN_SECONDS);tcheck(!str_contains(wp_json_encode(AISA_Provider::observation($s)),'canary'),'invalid stored diagnostic fields never rendered');
$storageFilter=function(){return array('invalid'=>'synthetic storage refusal');};add_filter('pre_set_transient_'.AISA_Provider::OBSERVATION,$storageFilter);delete_transient(AISA_Provider::PROBE_LIMIT);$n=count($calls);$probePost();runop(array('AISA_Agent','provider_probe'),'denied');tcheck(count($calls)===$n,'diagnostic storage refusal stops probe before outbound request');remove_filter('pre_set_transient_'.AISA_Provider::OBSERVATION,$storageFilter);
$responseOverride=null;delete_transient(AISA_Provider::PROBE_LIMIT);delete_transient(AISA_Provider::OBSERVATION);update_option('aisa_settings',$providerSettingsBefore);
