<?php
// Fixture preparation only. Subsequent requests enter real index.php with no preset principal.
require __DIR__.'/wp-load.php';
$s=AISA_Agent::defaults(); $s['enabled']=1; $s['customer_tickets']=1; $s['mcp']=getenv('AISA_DEFER_MCP')==='1'?0:1;
$s['provider']='local'; $s['model_mode']='manual'; $s['model']='synthetic-wire-model'; $s['endpoint']='https://wire.fixture.invalid/chat'; $s['hourly_limit']=1000; $s['daily_limit']=1000;
update_option('aisa_settings',$s);
$page=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Wirequartz policy','post_content'=>'Wirequartz public synthetic fact.'));
$record=(new ReflectionMethod('AISA_Agent','source_id'))->invoke(null,$page);
update_post_meta($record,'aisa_approved_hash',(new ReflectionMethod('AISA_Agent','review_hash'))->invoke(null,get_post($record)));
update_option('aisa_wire_record',$record); update_option('aisa_wire_calls',0);
echo wp_json_encode(array('scope'=>AISA_Memory::scope(),'plugin_version'=>AISA_VERSION));
