<?php
// Included by the disposable public-policy fixture, before its explicit manual-mode tests.
$freshPolicy=AISA_Agent::settings();qc($freshPolicy['public_sync']===1&&$freshPolicy['public_reference']===1&&$freshPolicy['public_sync_types']===array('post','page','product'),'fresh activation enables eligible public website/catalog references');
qc(!$freshPolicy['enabled']&&!$freshPolicy['mcp']&&!$freshPolicy['memory'],'fresh auto-catalog does not enable chat/model or privileged connections');
qc(AISA_Public_Sync::all_public()&&wp_next_scheduled(AISA_Public_Sync::DAILY),'fresh bounded job policy and daily opportunity configured');
delete_option(AISA_Agent::OPTION);
function freshProduct42($title,$status='publish',$visibility='visible'){$p=new WC_Product_Simple();$p->set_name($title);$p->set_status($status);$p->set_catalog_visibility($visibility);$p->set_regular_price('12.34');$p->set_short_description('Synthetic public short detail.');$p->set_description('SYNTHETIC_PUBLIC_FULL_DETAIL_42 - current public product body.');$p->set_purchase_note('PRIVATE_FULFILMENT_CANARY_42');$p->save();return$p;}
$existing42=freshProduct42('Existing before install42');$draft42=freshProduct42('Draft42','draft');$hidden42=freshProduct42('Hidden42','publish','hidden');$private42=freshProduct42('Private42','private');
qc(!qa($existing42->get_id()),'pre-install existing product starts without reference authorization');
AISA_Agent::activate();$initial42=AISA_Agent::settings();
qc($initial42['public_sync_types']===array('post','page','product')&&AISA_Public_Sync::all_public(),'fresh install needs no separate admin enablement');
qc(qa($existing42->get_id()),'bounded first batch authorizes ordinary existing public product');
foreach(array($draft42,$hidden42,$private42)as$denied42)qc(!qa($denied42->get_id()),'nonpublic catalog is not auto-authorized');
$new42=freshProduct42('Fresh new public42');qc(qa($new42->get_id()),'new Woo CRUD product immediately eligible after save without review/cron');
$new42->set_regular_price('23.45');$new42->set_description('SYNTHETIC_PUBLIC_FULL_DETAIL_42 - updated current body.');$new42->save();$source42=get_post(qr($new42->get_id()));
qc(qa($new42->get_id())&&AISA_Agent::source_is_current($source42),'after-product-object-save sees final price/body metadata');
qc(str_contains($source42->post_content,'updated current body')&&!str_contains($source42->post_content,'PRIVATE_FULFILMENT_CANARY_42'),'public full body indexed without purchase notes');
$import42=wp_insert_post(array('post_type'=>'aisa_knowledge','post_status'=>'private','post_title'=>'Imported42','post_content'=>'Synthetic upload text.'));qc(!AISA_Agent::knowledge_approved(get_post($import42)),'fresh public mode does not approve imports/teaching');
$privateLink42=freshProduct42('Accidental signed URL42');$privateLink42->set_description('Not public reference: https://aisa.local.test/?download_file=1&order_key=synthetic');$privateLink42->save();qc(!qa($privateLink42->get_id()),'automatic reference excludes accidental signed/private file URL in public description');
wp_delete_post($source42->ID,true);qc(!qr($new42->get_id()),'simulate not-yet-indexed eligible public source');
$connection42=AISA_Agent::settings();$connection42['enabled']=1;$connection42['provider']='local';$connection42['endpoint']='https://fresh.fixture.invalid/chat';$connection42['model']='fixture';$connection42['model_mode']='manual';$connection42['hourly_limit']=500;$connection42['daily_limit']=500;update_option(AISA_Agent::OPTION,$connection42,false);
$freshCalls42=0;$freshBody42='';$freshTransport42=function($pre,$args,$url)use(&$freshCalls42,&$freshBody42){qc($url==='https://fresh.fixture.invalid/chat','auto-catalog test uses only isolated fixture transport');$freshCalls42++;$freshBody42=$args['body'];return array('headers'=>array(),'response'=>array('code'=>200),'body'=>wp_json_encode(array('choices'=>array(array('message'=>array('role'=>'assistant','content'=>'Synthetic public answer.'),'finish_reason'=>'stop')))));};add_filter('pre_http_request',$freshTransport42,PHP_INT_MAX,3);
$ask42=new WP_REST_Request('POST','/aisa/v1/chat');$ask42->set_param('product_id',$new42->get_id());$ask42->set_param('message','Is product ka detail Hinglish mein batao.');$answer42=AISA_Agent::chat($ask42);
qc(!is_wp_error($answer42)&&$freshCalls42===1,'eligible selected product refreshes once before isolated answer');
qc(str_contains($freshBody42,'SYNTHETIC_PUBLIC_FULL_DETAIL_42')&&str_contains($freshBody42,'23.45')&&!str_contains($freshBody42,'PRIVATE_FULFILMENT_CANARY_42'),'selected answer receives bounded current public body/price, never purchase metadata');
AISA_Public_Sync::hold($new42->get_id());$denied42=AISA_Agent::chat($ask42);qc(is_wp_error($denied42)&&$denied42->get_error_code()==='product_unapproved'&&$freshCalls42===1,'explicit exclusion cannot be bypassed by selected-product refresh');
$new42->set_status('draft');$new42->save();qc(!qa($new42->get_id()),'unpublish removes usable product reference');$new42->set_status('publish');$new42->save();qc(!qa($new42->get_id()),'republish preserves admin hold');AISA_Public_Sync::release($new42->get_id());qc(qa($new42->get_id()),'deliberately restored public source uses current reference');
remove_filter('pre_http_request',$freshTransport42,PHP_INT_MAX);
$off42=AISA_Agent::settings();$off42['public_sync']=0;$off42['public_reference']=0;$off42['public_sync_types']=array();update_option(AISA_Agent::OPTION,$off42,false);AISA_Public_Sync::configure($connection42,$off42);AISA_Agent::activate();qc(AISA_Agent::settings()===$off42,'reactivation never overwrites existing deliberate opt-out or provider fields');
foreach(array($existing42,$new42,$draft42,$hidden42,$private42,$privateLink42)as$cleanup42)wp_delete_post($cleanup42->get_id(),true);wp_delete_post($import42,true);
update_option(AISA_Agent::OPTION,AISA_Agent::defaults(),false);

