<?php
defined('ABSPATH') || exit;

/** Private, consented website conversations. No external delivery or autonomous account tools. */
final class AISA_Live_Inbox {
    public static function boot() {
        add_action('rest_api_init', function() {
            foreach (array('conversation_poll','conversation_message') as $route) {
                register_rest_route('aisa/v1','/'.$route,array('methods'=>'POST','permission_callback'=>'__return_true','callback'=>array(__CLASS__,$route)));
            }
            register_rest_route('aisa/v1','/inbox_status',array('methods'=>'POST','permission_callback'=>function(){return current_user_can('manage_options');},'callback'=>array(__CLASS__,'inbox_status')));
        });
    }
    public static function control($s) {
        return $s['desk']??array('mode'=>'ai','assignee'=>0,'generation'=>1,'pending'=>-1,'read'=>0,'topic'=>'general','priority'=>'normal','status'=>'open','receipts'=>array());
    }
    public static function valid($d) {
        if (!is_array($d)||array_keys($d)!==array('mode','assignee','generation','pending','read','topic','priority','status','receipts')) return false;
        if (!in_array($d['mode'],array('ai','human','closed'),true)||!in_array($d['topic'],array('general','product','policy','purchase'),true)||!in_array($d['priority'],array('normal','urgent'),true)||!in_array($d['status'],array('open','resolved'),true)) return false;
        foreach(array('assignee','generation','pending','read') as $k) if(!is_int($d[$k])) return false;
        if($d['assignee']<0||$d['generation']<1||$d['pending']< -1||$d['pending']>=AISA_Conversations::TURNS||$d['read']<0||$d['read']>AISA_Conversations::TURNS||!is_array($d['receipts'])||count($d['receipts'])>20) return false;
        foreach($d['receipts'] as $k=>$v) if(!is_string($k)||!preg_match('/^[a-f0-9]{32}$/D',$k)||!is_int($v)||$v<0||$v>=AISA_Conversations::TURNS) return false;
        return ($d['mode']==='human'? $d['assignee']>0 : $d['assignee']===0);
    }
    private static function error($code,$message,$status=409) { return new WP_Error('conversation_'.$code,$message,array('status'=>$status)); }
    private static function locked($fn) {
        if(!add_option(AISA_Conversations::LOCK,time(),'','no')) return self::error('busy','Inbox is busy. Refresh before retrying.');
        try{return $fn();}finally{delete_option(AISA_Conversations::LOCK);}
    }
    private static function key($r) {
        $valid=AISA_Memory::validate($r->get_param('scope'));if(is_wp_error($valid))return $valid;
        $session=$r->get_param('session');if(!is_string($session)||!preg_match('/^[a-f0-9]{32}$/D',$session))return self::error('session','Reload to start a safe support session.',400);
        return hash_hmac('sha256',AISA_Memory::scope().'|'.$session,wp_salt('nonce'));
    }
    private static function find($key) {
        $rows=get_posts(array('post_type'=>AISA_Conversations::TYPE,'post_status'=>array_merge(array('private'),array_values(get_post_stati())),'posts_per_page'=>2,'meta_key'=>'aisa_conversation_key','meta_value'=>$key,'suppress_filters'=>true));
        if(!$rows)return null;if(count($rows)!==1)return self::error('unavailable','Conversation unavailable.',404);
        clean_post_cache($rows[0]->ID);$p=get_post($rows[0]->ID);$s=AISA_Conversations::state($p);
        if(!$s||$p->post_status!=='private'||$s['owner']!==get_current_user_id()||$s['expires']<=time())return self::error('unavailable','Conversation unavailable.',404);
        return array($p,$s);
    }
    private static function write($p,$s) {
        $json=wp_json_encode($s);if(!is_string($json)||strlen($json)>AISA_Conversations::BYTES)return self::error('full','Conversation is full. Use the contact options.',400);
        global $wpdb;
        $ok=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_content=%s WHERE ID=%d AND post_content=%s AND post_status=%s",$json,$p->ID,$p->post_content,$p->post_status));
        clean_post_cache($p->ID);
        if($ok!==1||AISA_Conversations::state(get_post($p->ID))!==$s)return self::error('save','Conversation changed or could not be saved. Refresh before retrying.',503);
        return true;
    }
    /** Also consulted without consent: withdrawing capture never silently returns a claimed chat to AI. */
    public static function model_gate($r) {
        if($r->get_param('scope')===null||$r->get_param('session')===null)return true;
        $key=self::key($r);if(is_wp_error($key))return $key;$row=self::find($key);if(is_wp_error($row))return $row;if(!$row)return true;
        $d=self::control($row[1]);
        if($d['mode']==='human')return self::error('human','A support agent has taken over. Enable saved-chat consent to continue this conversation.');
        if($d['mode']==='closed')return self::error('closed','This conversation is resolved. Use the support contact options.');
        if($d['pending']!==-1)return self::error('busy','An AI reply is already being prepared. Refresh before sending again.');
        return true;
    }
    /** Persist the question before transport, then fence late completions using an immutable generation. */
    public static function begin($r,$question) {
        if($r->get_param('scope')===null||$r->get_param('session')===null)return null;
        $key=self::key($r);if(is_wp_error($key))return $key;
        if(!AISA_Conversations::config()['enabled']||$r->get_param('archive_consent')!==true){
            $row=self::find($key);if(is_wp_error($row))return $row;if(!$row)return null;$d=self::control($row[1]);
            if($d['mode']!=='ai'||$d['pending']!==-1)return self::error('changed','Support control changed. Refresh first.');
            return array('id'=>(int)$row[0]->ID,'key'=>$key,'owner'=>get_current_user_id(),'site'=>get_current_blog_id(),'scope'=>AISA_Memory::scope(),'turn'=>-1,'generation'=>$d['generation'],'config'=>AISA_Conversations::config_revision(),'record'=>false);
        }
        return self::locked(function()use($r,$question,$key){
            $row=self::find($key);if(is_wp_error($row))return $row;$p=$row?$row[0]:null;$now=time();
            $s=$row?$row[1]:array('site'=>get_current_blog_id(),'owner'=>get_current_user_id(),'key'=>$key,'created'=>$now,'updated'=>$now,'expires'=>$now+AISA_Conversations::TTL,'revision'=>0,'reviewed'=>0,'messages'=>array());
            $d=self::control($s);if($d['mode']!=='ai'||$d['pending']!==-1)return self::error('changed','This chat is controlled by support or already preparing a reply.');
            if(count($s['messages'])>=AISA_Conversations::TURNS)return self::error('full','Conversation is full. Use the contact options.',400);
            if(!$p&&count(get_posts(array('post_type'=>AISA_Conversations::TYPE,'post_status'=>array_merge(array('private'),array_values(get_post_stati())),'posts_per_page'=>101,'fields'=>'ids','suppress_filters'=>true)))>=AISA_Conversations::LIMIT)return self::error('full','Support inbox is full. Use contact options.',503);
            $turn=count($s['messages']);$d['pending']=$turn;$s['messages'][]=array('at'=>$now,'question'=>AISA_Conversations::redacted($question),'answer'=>'','outcome'=>'pending');$s['desk']=$d;$s['updated']=$now;$s['revision']++;$s['reviewed']=0;
            if($p){$saved=self::write($p,$s);if(is_wp_error($saved))return $saved;$id=$p->ID;}
            else{$id=wp_insert_post(wp_slash(array('post_type'=>AISA_Conversations::TYPE,'post_status'=>'private','post_author'=>get_current_user_id(),'post_title'=>'Support conversation','post_content'=>wp_json_encode($s),'meta_input'=>array('aisa_conversation_key'=>$key,'aisa_conversation_owner'=>(string)get_current_user_id()))),true);if(!$id||is_wp_error($id)||AISA_Conversations::state(get_post($id))!==$s){if(is_int($id)&&$id>0)wp_delete_post($id,true);return self::error('save','Chat could not be saved. No AI request was sent.',503);}}
            if(!wp_next_scheduled(AISA_Conversations::CLEAN))wp_schedule_single_event(time()+HOUR_IN_SECONDS,AISA_Conversations::CLEAN);
            return array('id'=>(int)$id,'key'=>$key,'owner'=>get_current_user_id(),'site'=>get_current_blog_id(),'scope'=>AISA_Memory::scope(),'turn'=>$turn,'generation'=>$d['generation'],'config'=>AISA_Conversations::config_revision(),'record'=>true);
        });
    }
    public static function finish($token,$answer,$outcome='answer') {
        return self::locked(function()use($token,$answer,$outcome){
            clean_post_cache($token['id']);$p=get_post($token['id']);$s=AISA_Conversations::state($p);$d=$s?self::control($s):null;
            if(!$s||$p->post_status!=='private'||$s['expires']<=time()||($token['record']&&!AISA_Conversations::config()['enabled'])||!hash_equals($token['config'],AISA_Conversations::config_revision())||$token['site']!==get_current_blog_id()||$token['owner']!==get_current_user_id()||!hash_equals($token['scope'],AISA_Memory::scope())||$s['key']!==$token['key']||$d['mode']!=='ai'||$d['generation']!==$token['generation']||($token['record']&&($d['pending']!==$token['turn']||($s['messages'][$token['turn']]['outcome']??'')!=='pending')))return self::error('changed','AI delivery stopped because support control or this session changed.');
            if(!$token['record'])return true;
            $s['messages'][$token['turn']]['answer']=AISA_Conversations::redacted($answer,6000);$s['messages'][$token['turn']]['outcome']=$outcome;$d['pending']=-1;$s['desk']=$d;$s['updated']=time();$s['revision']++;return self::write($p,$s);
        });
    }
    private static function active_request($r) {
        if(!AISA_Agent::settings()['enabled']||!AISA_Conversations::config()['enabled'])return self::error('disabled','Saved-chat support is disabled.',503);
        if($r->get_param('archive_consent')!==true)return self::error('consent','Choose saved-chat consent to use the private support conversation.',403);
        $key=self::key($r);if(is_wp_error($key))return $key;
        // Bounded independent poll budget, so polling does not consume model/chat quotas.
        $bucket='aisa_live_'.hash_hmac('sha256',get_current_blog_id().'|'.($_SERVER['REMOTE_ADDR']??'unknown').'|'.gmdate('YmdHi'),wp_salt('nonce'));
        if(!add_option($bucket.'_lock',time(),'','no'))return self::error('busy','Refresh support status shortly.');
        try{$n=(int)get_transient($bucket);if($n>=120)return self::error('limit','Refresh support status shortly.',429);set_transient($bucket,$n+1,120);}finally{delete_option($bucket.'_lock');}
        return self::find($key);
    }
    private static function public_state($s,$from=0) {
        $d=self::control($s);$events=array();$cursor=0;
        foreach($s['messages'] as $i=>$m){$seq=$i*2+1;if($m['question']!==''&&$seq>$from)$events[]=array('sequence'=>$seq,'kind'=>'customer','text'=>AISA_Conversations::redacted($m['question']));$cursor=max($cursor,$seq);if($m['outcome']!=='pending'){$seq++;if($seq>$from)$events[]=array('sequence'=>$seq,'kind'=>$m['outcome']==='human'?'human':($m['outcome']==='error'?'notice':'ai'),'text'=>AISA_Conversations::redacted($m['answer'],6000));$cursor=$seq;}}
        return array('mode'=>$d['mode'],'generation'=>$d['generation'],'pending'=>$d['pending']!==-1,'events'=>$events,'cursor'=>$cursor,'reviewer_available'=>$d['mode']!=='human'||user_can($d['assignee'],'manage_options'));
    }
    public static function conversation_poll($r) {
        $row=self::active_request($r);if(is_wp_error($row))return $row;$from=$r->get_param('after')??0;if(!is_int($from)||$from<0||$from>40)return self::error('cursor','Invalid conversation cursor.',400);
        $out=$row?self::public_state($row[1],$from):array('mode'=>'ai','generation'=>1,'pending'=>false,'events'=>array(),'cursor'=>0,'reviewer_available'=>true);
        $response=rest_ensure_response($out);$response->header('Cache-Control','private, no-store');return $response;
    }
    public static function conversation_message($r) {
        $row=self::active_request($r);if(is_wp_error($row))return $row;if(!$row)return self::error('unavailable','Conversation unavailable.',404);
        $message=$r->get_param('message');$request=$r->get_param('request_id');if(!is_string($message)||trim($message)===''||strlen($message)>2000||!is_string($request)||!preg_match('/^[a-f0-9]{32}$/D',$request))return self::error('message','Use a short text message and a current request ID.',400);
        $id=$row[0]->ID;$owner=get_current_user_id();$scope=AISA_Memory::scope();
        return self::locked(function()use($id,$message,$request,$owner,$scope){
            clean_post_cache($id);$p=get_post($id);$s=AISA_Conversations::state($p);if(!$s||$p->post_status!=='private'||$s['expires']<=time()||!AISA_Conversations::config()['enabled']||get_current_user_id()!==$owner||$s['owner']!==$owner||!hash_equals($scope,AISA_Memory::scope()))return self::error('unavailable','Conversation unavailable.',404);$d=self::control($s);
            if($d['mode']!=='human'||$d['status']!=='open'||!user_can($d['assignee'],'manage_options'))return self::error('changed','Human support is not currently available. No message was sent.');
            if(isset($d['receipts'][$request]))return rest_ensure_response(array('saved'=>true,'duplicate'=>true));
            if(count($s['messages'])>=AISA_Conversations::TURNS)return self::error('full','Conversation is full. Use contact options.',400);
            $d['receipts'][$request]=count($s['messages']);$s['messages'][]=array('at'=>time(),'question'=>AISA_Conversations::redacted($message),'answer'=>'','outcome'=>'pending');$s['desk']=$d;$s['updated']=time();$s['revision']++;$s['reviewed']=0;$saved=self::write($p,$s);if(is_wp_error($saved))return $saved;$response=rest_ensure_response(array('saved'=>true,'duplicate'=>false));$response->header('Cache-Control','private, no-store');return $response;
        });
    }
    public static function staff($op,$id,$revision,$data=array()) {
        if(!current_user_can('manage_options'))return self::error('auth','Administrator access required.',403);
        return self::locked(function()use($op,$id,$revision,$data){
            clean_post_cache($id);$p=get_post($id);$s=AISA_Conversations::state($p);if(!$s||$p->post_status!=='private'||$s['expires']<=time())return self::error('unavailable','Chat not found.',404);
            if((string)$s['revision']!==(string)$revision)return self::error('changed','New messages or actions arrived. Refresh before applying.');$d=self::control($s);$uid=get_current_user_id();
            if(in_array($op,array('takeover','resume','resolve','human_reply'),true)&&!AISA_Conversations::config()['enabled'])return self::error('disabled','Conversation capture is disabled. No live action is available.',503);
            if($d['mode']==='human'&&$d['assignee']!==$uid&&in_array($op,array('takeover','resume','resolve','human_reply'),true))return self::error('assigned','Another administrator owns this chat. No automatic reassignment.');
            if($op==='takeover'){
                if($d['mode']==='closed')return self::error('closed','Reopen the conversation first.');
                $pending=$d['pending'];if($pending>=0&&isset($s['messages'][$pending])){$s['messages'][$pending]['answer']='AI reply stopped because a support agent took over.';$s['messages'][$pending]['outcome']='error';}
                $d['mode']='human';$d['assignee']=$uid;$d['generation']++;$d['pending']=-1;$d['status']='open';
            }elseif($op==='resume'||$op==='resolve'){
                if($d['pending']!==-1)return self::error('busy','Take over the pending AI reply first.');
                $d['mode']=$op==='resolve'?'closed':'ai';$d['status']=$op==='resolve'?'resolved':'open';$d['assignee']=0;$d['generation']++;
            }elseif($op==='human_reply'){
                $text=$data['message']??null;$receipt=$data['request_id']??null;
                if($d['mode']!=='human'||$d['assignee']!==$uid)return self::error('claim','Take over this chat before replying.');
                if(!is_string($text)||trim($text)===''||strlen($text)>2000||AISA_Conversations::redacted($text,2000)!==sanitize_textarea_field($text)||!is_string($receipt)||!preg_match('/^[a-f0-9]{32}$/D',$receipt))return self::error('message','Use plain public-support text without credentials, private details or links.',400);
                if(isset($d['receipts'][$receipt]))return self::error('duplicate','This reply was already saved. Refresh first.');
                if(count($s['messages'])>=AISA_Conversations::TURNS)return self::error('full','This conversation is full.',400);
                $d['receipts'][$receipt]=count($s['messages']);$s['messages'][]=array('at'=>time(),'question'=>'','answer'=>sanitize_textarea_field($text),'outcome'=>'human');$s['reviewed']=0;
            }elseif($op==='mark_read'){$d['read']=count($s['messages']);}
            elseif($op==='organize'){
                if(!in_array($data['topic']??null,array('general','product','policy','purchase'),true)||!in_array($data['priority']??null,array('normal','urgent'),true))return self::error('labels','Choose valid inbox labels.',400);$d['topic']=$data['topic'];$d['priority']=$data['priority'];
            }else return self::error('action','Unknown inbox action.',400);
            $s['desk']=$d;$s['updated']=time();$s['revision']++;$saved=self::write($p,$s);if(is_wp_error($saved))return $saved;AISA_Conversations::audit('inbox '.$op);return true;
        });
    }
    /** Called while the parent admin action holds the same archive lock. */
    public static function invalidate($id) {
        clean_post_cache($id);$p=get_post($id);$s=AISA_Conversations::state($p);if(!$s)return self::error('unavailable','Chat is unavailable.',404);$d=self::control($s);
        if($d['pending']>=0){$s['messages'][$d['pending']]['answer']='AI reply stopped because the conversation was removed or restored.';$s['messages'][$d['pending']]['outcome']='error';}
        $d['generation']++;$d['pending']=-1;$s['desk']=$d;return self::write($p,$s);
    }
    public static function brief($rows) {
        $out=array('chats'=>count($rows),'unread'=>0,'human'=>0,'open'=>0,'failed'=>0,'drafts_needed'=>0,'today'=>0);
        foreach($rows as $row){$s=$row['state'];$d=self::control($s);$out['unread']+=(int)(count($s['messages'])>$d['read']);$out['human']+=(int)($d['mode']==='human');$out['open']+=(int)($d['status']==='open');$out['drafts_needed']+=(int)!$s['reviewed'];$out['today']+=(int)($s['updated']>=time()-DAY_IN_SECONDS);foreach($s['messages'] as $m)$out['failed']+=(int)($m['outcome']==='error');}
        return $out;
    }
    public static function inbox_status($r) {
        $rows=AISA_Conversations::records();$out=array();foreach($rows as$row){$s=$row['state'];$d=self::control($s);$out[]=array('id'=>$row['id'],'revision'=>$s['revision'],'mode'=>$d['mode'],'pending'=>$d['pending']!==-1,'unread'=>count($s['messages'])>$d['read']);}
        $response=rest_ensure_response(array('chats'=>$out,'brief'=>self::brief($rows)));$response->header('Cache-Control','private, no-store');return $response;
    }
    private static function inbox_url($args=array()) {
        return add_query_arg(array_merge(array('page'=>'aisa','view'=>'inbox'),$args),admin_url('admin.php'));
    }
    public static function navigation($source) {
        $allowed=array('all','unread','ai','open','human','resolved','urgent','product','policy','purchase');
        $filter=is_string($source['chat_filter']??null)&&in_array($source['chat_filter'],$allowed,true)?$source['chat_filter']:'all';
        return array('chat_page'=>max(1,min(10,absint($source['chat_page']??1))),'chat_filter'=>$filter,'chat_search'=>is_string($source['chat_search']??null)?substr(sanitize_text_field(wp_unslash($source['chat_search'])),0,100):'','chat_trash'=>!empty($source['chat_trash'])?1:null);
    }
    public static function render($trash=false) {
        $rows=AISA_Conversations::records($trash);$brief=self::brief($rows);$counts=array('all'=>count($rows),'ai'=>0,'human'=>0,'unread'=>$brief['unread']);foreach($rows as$counted){$mode=self::control($counted['state'])['mode'];if(isset($counts[$mode]))$counts[$mode]++;}$nav=self::navigation($_GET);$filter=$nav['chat_filter'];$needle=$nav['chat_search'];
        $rows=array_values(array_filter($rows,function($row)use($filter,$needle){$s=$row['state'];$d=self::control($s);$match=$filter==='all'||($filter==='unread'&&count($s['messages'])>$d['read'])||$filter===$d['mode']||$filter===$d['status']||$filter===$d['priority']||$filter===$d['topic'];return $match&&($needle===''||stripos(wp_json_encode($s['messages'],JSON_UNESCAPED_UNICODE),$needle)!==false);}));
        $page=max(1,min(10,absint($_GET['chat_page']??1)));$pages=max(1,(int)ceil(count($rows)/10));$page=min($page,$pages);$slice=array_slice($rows,($page-1)*10,10);
        $requested=absint($_GET['chat_id']??0);$ids=array_column($slice,'id');$selected=in_array($requested,$ids,true)?$requested:($slice[0]['id']??0);
        $base=array('chat_filter'=>$filter,'chat_search'=>$needle,'chat_page'=>$page,'chat_trash'=>$trash?1:null);
        echo '<div class="aisa-inbox-desk aisa-messenger" data-aisa-inbox data-theme="'.esc_attr(AISA_Agent::settings()['admin_theme']).'" data-api="'.esc_url(rest_url('aisa/v1/inbox_status')).'" data-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'"><aside class="aisa-chat-rail" aria-label="Conversation list"><div class="aisa-chat-rail-head"><h3>'.($trash?'Deleted chats':'Chats').'</h3><a href="'.esc_url(self::inbox_url(array_merge($base,array('chat_id'=>$selected)))).'" class="button">Refresh Inbox</a></div><form method="get" class="aisa-inbox-filter"><input type="hidden" name="page" value="aisa"><input type="hidden" name="view" value="inbox">';if($trash)echo '<input type="hidden" name="chat_trash" value="1">';echo '<label>Search saved text <input type="search" name="chat_search" maxlength="100" value="'.esc_attr($needle).'" placeholder="Search chats"></label><div class="aisa-filter-row"><label>Show <select name="chat_filter">';foreach(array('all'=>'All chats','unread'=>'Unread','ai'=>'AI responding','open'=>'Open','human'=>'With support','resolved'=>'Resolved','urgent'=>'Urgent','product'=>'Product','policy'=>'Policy','purchase'=>'Purchase')as$k=>$label)echo '<option value="'.esc_attr($k).'" '.selected($filter,$k,false).'>'.esc_html($label).'</option>';echo '</select></label><button class="button">Filter</button></div></form><nav class="aisa-chat-chips" aria-label="Quick conversation filters">';foreach(array('all'=>'All','ai'=>'AI responding','human'=>'Human handoff','unread'=>'Unread')as$k=>$label)echo '<a href="'.esc_url(self::inbox_url(array_merge($base,array('chat_page'=>1,'chat_filter'=>$k)))).'" '.($filter===$k?'aria-current="page"':'').'>'.esc_html($label).'<span class="aisa-chat-count">'.(int)$counts[$k].'</span></a>';echo '</nav><nav class="aisa-chat-list" aria-label="Saved conversations">';
        if(!$slice)echo '<p class="aisa-empty">No saved conversations in this view.</p>';
        foreach($slice as$row){$s=$row['state'];$d=self::control($s);$id=$row['id'];$last=end($s['messages']);$preview=wp_html_excerpt($last?($last['question']?:$last['answer']):'No messages',88,'…');$status=$d['pending']!==-1?'AI preparing reply':($d['mode']==='human'?'Support agent active':($d['mode']==='closed'?'Resolved':'AI active'));echo '<a class="aisa-chat-item" data-chat-list-id="'.(int)$id.'" href="'.esc_url(self::inbox_url(array_merge($base,array('chat_id'=>$id)))).'" '.($selected===$id?'aria-current="true"':'').'><span class="aisa-chat-avatar" aria-hidden="true">'.($s['owner']?'C':'G').'</span><span class="aisa-chat-item-copy"><strong>Chat #'.(int)$id.'</strong><small>'.($s['owner']?'Account #'.(int)$s['owner']:'Guest').' · '.esc_html(gmdate('M j, H:i',$s['updated'])).' UTC</small><span class="aisa-chat-snippet">'.esc_html($preview).'</span><span class="aisa-chat-item-status">'.esc_html($status).(count($s['messages'])>$d['read']?' · Unread':'').'</span></span></a>';}
        echo '</nav>';if($pages>1){echo '<nav class="aisa-chat-pages" aria-label="Conversation pages">';for($i=1;$i<=$pages;$i++)echo '<a class="button" '.($page===$i?'aria-current="page"':'').' href="'.esc_url(self::inbox_url(array_merge($base,array('chat_page'=>$i)))).'">'.$i.'</a> ';echo '</nav>';}echo '</aside><section class="aisa-chat-room" aria-label="Selected conversation">';
        if(!$selected)echo '<div class="aisa-chat-empty"><span aria-hidden="true">✦</span><h3>Your support workspace</h3><p>Choose a saved conversation to review it and take control.</p><p>Only consented website conversations appear here.</p></div>';
        foreach($slice as$row){if($row['id']!==$selected)continue;$s=$row['state'];$d=self::control($s);$id=$row['id'];$live=(bool)AISA_Conversations::config()['enabled'];$status=$d['pending']!==-1?'AI preparing reply':($d['mode']==='human'?'Support agent active':($d['mode']==='closed'?'Resolved':'AI active'));
            echo '<article class="aisa-conversation" data-chat-id="'.(int)$id.'" data-chat-revision="'.(int)$s['revision'].'"><header class="aisa-chat-header"><button type="button" class="button aisa-mobile-back" data-aisa-chat-back aria-label="Back to chat list">Chats</button><span class="aisa-chat-avatar" aria-hidden="true">'.($s['owner']?'C':'G').'</span><div><h3>Chat #'.(int)$id.' · '.($s['owner']?'Account #'.(int)$s['owner']:'Guest').'</h3><span class="aisa-chat-channel">Website</span> <span data-chat-status>'.esc_html($status).'</span><span> · '.esc_html($d['priority']).' · '.esc_html($d['topic']).'</span></div></header><details class="aisa-thread-search" data-aisa-thread-search hidden><summary>Search conversation</summary><label><span>Find in conversation</span><input type="search" data-aisa-thread-query maxlength="100" placeholder="Search this chat"></label><div><button type="button" class="button" data-aisa-thread-prev aria-label="Previous matching message" disabled>Previous</button><button type="button" class="button" data-aisa-thread-next aria-label="Next matching message" disabled>Next</button></div><output data-aisa-thread-count role="status" aria-live="polite"></output></details>';
            if(!$trash){echo '<div class="aisa-inbox-actions">';foreach(array('takeover'=>'Take over from AI','resume'=>'Return to AI / reopen','resolve'=>'Resolve','mark_read'=>'Mark read')as$op=>$label){AISA_Conversations::form($op,array('id'=>$id,'revision'=>$s['revision']));echo '<button class="button" '.disabled(!$live,true,false).'>'.esc_html($label).'</button></form>';}echo '</div>';}
            echo '<div class="aisa-staff-log" role="log" aria-label="Saved conversation" tabindex="0">';
            foreach($s['messages']as$m){if($m['question']!=='')echo '<div class="aisa-staff-message customer" tabindex="-1"><strong>Customer</strong><p>'.nl2br(esc_html($m['question'])).'</p><time>'.esc_html(gmdate('H:i',$m['at'])).' UTC</time></div>';if($m['answer']!=='')echo '<div class="aisa-staff-message '.($m['outcome']==='human'?'human':($m['outcome']==='error'?'system':'ai')).'" tabindex="-1"><strong>'.($m['outcome']==='human'?'Support agent':($m['outcome']==='error'?'System notice':'AI assistant')).'</strong><p>'.nl2br(esc_html($m['answer'])).'</p></div>';}echo '</div><div class="aisa-chat-composer">';
            if(!$trash&&$live&&$d['mode']==='human'&&$d['assignee']===get_current_user_id()){AISA_Conversations::form('human_reply',array('id'=>$id,'revision'=>$s['revision'],'request_id'=>bin2hex(random_bytes(16))));echo '<label class="aisa-field"><span>Reply as support agent</span><textarea name="message" rows="2" maxlength="2000" placeholder="Write a website reply…" required></textarea></label><button class="button button-primary">Send website reply</button><details class="aisa-reply-shortcuts" data-aisa-reply-shortcuts hidden><summary>Quick replies</summary><label>Quick reply <select data-aisa-reply-template><option value="">Choose a reply</option><option value="greeting">Greeting</option><option value="details">Ask for more details</option></select></label><button type="button" class="button" data-aisa-insert-reply>Insert into draft</button><output data-aisa-reply-feedback role="status" aria-live="polite"></output></details><p class="aisa-help">Only this customer’s consented website session. No email or external message. Do not include credentials, private details or links; use secure My purchases for orders/downloads.</p></form>';}
            else echo '<p>'.esc_html($trash?'Deleted conversation — restore to manage it.':(!$live?'Website replies are currently disabled.':($d['mode']==='human'?'Another administrator controls this chat.':($d['mode']==='closed'?'This conversation is resolved. Reopen or take over to continue.':'AI controls this chat. Take over to write a support reply.')))).'</p>';
            echo '</div><details class="aisa-chat-tools"><summary>Conversation details and review</summary><p class="aisa-help">Take over prevents delivery of pending AI replies. An already-started provider request may still finish. Only the claiming administrator can reply or return control; missing reviewers are never replaced automatically.</p>';
            if(!$trash){
                AISA_Conversations::form('organize',array('id'=>$id,'revision'=>$s['revision']));echo '<div class="aisa-inbox-filter"><label>Topic <select name="topic">';foreach(array('general','product','policy','purchase')as$v)echo '<option '.selected($d['topic'],$v,false).'>'.esc_html($v).'</option>';echo '</select></label><label>Priority <select name="priority">';foreach(array('normal','urgent')as$v)echo '<option '.selected($d['priority'],$v,false).'>'.esc_html($v).'</option>';echo '</select></label><button class="button" '.disabled(!$live,true,false).'>Update labels</button></div></form>';
                AISA_Conversations::form('analyze',array('id'=>$id,'revision'=>$s['revision']));echo '<p class="aisa-help">Review this displayed redacted chat before sending it to the saved provider for an unapproved FAQ draft.</p><label><input type="checkbox" name="confirm_analysis" value="1" required> I reviewed this text and approve this analysis</label><button class="button">Suggest Training draft</button></form>';
            }
            AISA_Conversations::form($trash?'restore':'delete',array('id'=>$id,'revision'=>$s['revision']));echo '<button class="button">'.($trash?'Restore chat':'Delete chat').'</button></form></details></article>';
        }
        echo '</section></div><p data-aisa-inbox-updates role="status" aria-live="polite"></p>';
    }
}
