<?php
// Actual WP multipart upload and admin mutation callbacks, preset synthetic principal only.
require __DIR__.'/wp-load.php';
$case=getenv('AISA_KNOWLEDGE_CASE');$probe=getenv('AISA_KNOWLEDGE_PROBE')==='1';$checks=0;
function kcheck($ok,$label){global $checks;$checks++;if(!$ok){throw new RuntimeException('Knowledge assertion: '.$label);}}
function kmethod($name,...$args){return (new ReflectionMethod('AISA_Agent',$name))->invoke(null,...$args);}
class KnowledgeStopped extends RuntimeException {}
class KnowledgeRedirected extends RuntimeException {}
add_filter('wp_die_handler',function(){return function($message,$title='',$args=array()){$GLOBALS['knowledge_response']=$args['response']??null;throw new KnowledgeStopped(is_string($message)?strip_tags($message):'Native denial');};});
add_filter('wp_redirect',function($url){throw new KnowledgeRedirected($url);});
wp_set_current_user($case==='non-admin'?2:1);
$_POST['_wpnonce']=wp_create_nonce('aisa_admin');$_REQUEST['_wpnonce']=$_POST['_wpnonce'];
if($case==='bad-nonce'){$_POST['_wpnonce']='synthetic-invalid';$_REQUEST['_wpnonce']='synthetic-invalid';}
$id=0;$op='upload';
if(!str_starts_with($case,'upload')&&!in_array($case,array('non-admin','bad-nonce'),true)){
 $draft=str_starts_with($case,'reject')||$case==='approve-draft-fail';
 $id=wp_insert_post(array('post_type'=>$draft?'aisa_draft':'aisa_knowledge','post_status'=>$draft?'pending':'private','post_title'=>'Synthetic '.$case,'post_content'=>'Ingestquartz public synthetic support policy.'));
 $hash=kmethod('review_hash',get_post($id));
 if(str_starts_with($case,'revoke')||$case==='approve-idempotent'){update_post_meta($id,'aisa_approved_hash',$hash);}
 $_POST['id']=$id;$_POST['review_hash']=$hash;
 $op=str_starts_with($case,'remove')?'remove_knowledge':'review';
 $_POST['decision']=str_starts_with($case,'reject')?'reject':(str_starts_with($case,'revoke')?'revoke':'approve');
}
if($case==='upload-save-fail'||$case==='approve-draft-fail'){
 add_filter('wp_insert_post_empty_content',function($empty,$post){return $post['post_type']==='aisa_knowledge'?true:$empty;},PHP_INT_MAX,2);
}
if($case==='approve-meta-fail'){add_filter('update_post_metadata',function($pre,$pid,$key){return $key==='aisa_approved_hash'?false:$pre;},PHP_INT_MAX,3);}
if($case==='revoke-meta-fail'){add_filter('delete_post_metadata',function($pre,$pid,$key){return $key==='aisa_approved_hash'?false:$pre;},PHP_INT_MAX,3);}
if(in_array($case,array('remove-fail','reject-fail'),true)){add_filter('pre_delete_post',function($pre,$post)use($id){return $post->ID===$id?false:$pre;},PHP_INT_MAX,2);}
if($case==='upload-size-mismatch'){$_FILES['knowledge']['size']=1;}
$before=get_posts(array('post_type'=>'aisa_knowledge','post_status'=>'private','posts_per_page'=>-1,'fields'=>'ids'));
$auditBefore=(int)max(array_merge(array(0),get_posts(array('post_type'=>'aisa_audit','post_status'=>'private','posts_per_page'=>-1,'fields'=>'ids'))));
$outcome='returned';
try{AISA_Agent::$op();}catch(KnowledgeStopped $e){$outcome='denied';}catch(KnowledgeRedirected $e){$outcome='redirected';kcheck(str_starts_with($e->getMessage(),'https://aisa.local.test/wp-admin/'),'redirect remains local');}
$after=get_posts(array('post_type'=>'aisa_knowledge','post_status'=>'private','posts_per_page'=>-1,'fields'=>'ids'));$created=array_values(array_diff($after,$before));
$audit=get_posts(array('post_type'=>'aisa_audit','post_status'=>'private','posts_per_page'=>-1));$newAudit=array_values(array_filter($audit,fn($p)=>$p->ID>$auditBefore));
$observation=array('case'=>$case,'outcome'=>$outcome,'response_argument'=>$GLOBALS['knowledge_response']??null,'new_knowledge'=>count($created),'success_audits'=>count($newAudit),'record_exists'=>$id?(bool)get_post($id):null,'approved'=>$id?(bool)kmethod('approved',get_post($id)):null);
$expectedDenied=in_array($case,array('upload-save-fail','upload-empty','upload-binary','upload-utf8','upload-oversize','upload-size-mismatch','upload-extension','approve-meta-fail','revoke-meta-fail','approve-draft-fail','remove-fail','reject-fail','non-admin','bad-nonce'),true);
if(!$probe){
 kcheck($outcome===($expectedDenied?'denied':'redirected'),'truthful outcome for '.$case);
 if($expectedDenied){kcheck(!$newAudit,'failed mutation creates no success audit');}
 if($expectedDenied){$code=in_array($case,array('non-admin','bad-nonce'),true)?403:(str_starts_with($case,'upload')&&$case!=='upload-save-fail'?400:503);kcheck(($GLOBALS['knowledge_response']??null)===$code,'correct native wp_die response argument for '.$case);}
 if(str_starts_with($case,'upload')&&$expectedDenied){kcheck(!$created,'failed upload creates no knowledge');}
 if($case==='upload-ok'){
  kcheck(count($created)===1,'real multipart creates one knowledge record');$p=get_post($created[0]);
  kcheck($p->post_content==="Ingestquartz path C:\\support\\policy\nनमस्ते public help.",'UTF8 and literal backslashes survive native storage');
  kcheck(!kmethod('approved',$p),'import remains unapproved');
  kcheck(kmethod('knowledge','Ingestquartz')===array(),'unapproved imported source is unavailable to chat');
  $r=new WP_REST_Request('POST','/aisa/v1/mcp');$r->set_body('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"search_knowledge","arguments":{"query":"Ingestquartz"}}}');
  kcheck(json_decode(AISA_Agent::mcp($r)->get_data()['result']['content'][0]['text'],true)===array(),'MCP shares import approval gate');
 }
 if(in_array($case,array('approve-meta-fail','approve-draft-fail'),true)){kcheck(!kmethod('approved',get_post($id)),'failed approval stays unavailable');}
 if($case==='approve-idempotent'){kcheck(kmethod('approved',get_post($id)),'unchanged approval remains idempotent');}
 if($case==='revoke-meta-fail'){kcheck(kmethod('approved',get_post($id)),'failed revocation is reported honestly without false claim');}
 if(in_array($case,array('remove-fail','reject-fail'),true)){kcheck((bool)get_post($id),'native deletion refusal preserves record');}
 if(in_array($case,array('remove-ok','reject-ok'),true)){kcheck(!get_post($id),'successful removal/rejection deletes record');}
}
foreach(array(1,2,3) as $uid){kcheck(get_userdata($uid)->user_pass==='*'&&!WP_Session_Tokens::get_instance($uid)->get_all()&&!get_user_meta($uid,'_application_passwords',true),'no credentials or signed sessions');}
echo wp_json_encode(array('status'=>$probe?'OBSERVED':'PASS','checks'=>$checks,'plugin_version'=>AISA_VERSION,'observation'=>$observation));
