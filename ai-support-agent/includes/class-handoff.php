<?php
defined('ABSPATH') || exit;

/** Structured account-linked requests only; no messages, model context or notification dispatch. */
final class AISA_Handoff {
    const TYPE='aisa_request';
    const LIMIT=100;
    const OWNER_LIMIT=3;
    const TTL=7*DAY_IN_SECONDS;
    const RATES='aisa_request_rates';
    public static function topics() { return AISA_Inbox::topics(); }
    public static function boot() {
        add_action('init',array(__CLASS__,'types'));
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_post_aisa_request',array(__CLASS__,'handle'));
        add_action(AISA_Inbox::HOOK,array(__CLASS__,'cleanup'));
        add_filter('rest_post_dispatch',array(__CLASS__,'no_store'),10,3);
    }
    public static function types() {
        // Generic WordPress post editors/handlers must not bypass the controlled review workflow.
        $caps=array_fill_keys(array('edit_post','read_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts','create_posts','delete_posts','delete_others_posts','edit_private_posts','edit_published_posts','delete_private_posts','delete_published_posts'),'do_not_allow');
        register_post_type(self::TYPE,array('public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_ui'=>false,'show_in_rest'=>false,'can_export'=>false,'delete_with_user'=>true,'supports'=>array(),'map_meta_cap'=>false,'capabilities'=>$caps));
    }
    public static function routes() {
        foreach (array('submit','status') as $operation) { register_rest_route('aisa/v1','/tickets/'.$operation,array('methods'=>'POST','permission_callback'=>array(__CLASS__,'permission'),'callback'=>array(__CLASS__,$operation))); }
    }
    private static function error($code,$message,$status=400) { return new WP_Error($code,$message,array('status'=>$status)); }
    public static function permission($r) {
        if (!is_user_logged_in() || get_current_user_id()<1) { return self::error('ticket_auth','Sign in to use your support tickets.',401); }
        if (!wp_verify_nonce($r->get_header('X-WP-Nonce'),'wp_rest')) { return self::error('ticket_nonce','Reload the page to verify your support session.',403); }
        if (!AISA_Agent::settings()['customer_tickets']) { return self::error('tickets_disabled','Account-linked support tickets are unavailable.',503); }
        return true;
    }
    public static function no_store($response,$server,$request) {
        if (in_array($request->get_route(),array('/aisa/v1/tickets/submit','/aisa/v1/tickets/status'),true)) { $response->header('Cache-Control','private, no-store'); }
        return $response;
    }
    private static function input($r,$keys) {
        $permission=self::permission($r); if (is_wp_error($permission)) { return $permission; }
        if (strlen($r->get_body())>2048) { return self::error('ticket_input','Support request is too large.',413); }
        $p=$r->get_json_params();
        if (!is_array($p) || count($p)!==count($keys) || array_diff(array_keys($p),$keys)) { return self::error('ticket_input','Use only the structured support form.'); }
        $scope=AISA_Memory::validate($p['scope']??null); return is_wp_error($scope)?$scope:$p;
    }
    private static function records($owner=0) {
        $args=array('post_type'=>self::TYPE,'post_status'=>AISA_Inbox::all_statuses(),'posts_per_page'=>self::LIMIT+1,'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true);
        if ($owner) { $args['author']=$owner; } return get_posts($args);
    }
    private static function state($post) {
        if (!$post || $post->post_type!==self::TYPE || $post->post_status!=='private' || !AISA_Inbox::owner_matches($post) || strlen($post->post_content)>2048) { return false; }
        $s=json_decode($post->post_content,true,8);
        if (!is_array($s) || array_keys($s)!==array('site','topic','status','created','expires','updated','revision','reviewer','request_hash','events') || $s['site']!==get_current_blog_id() || !is_string($s['topic']) || !isset(self::topics()[$s['topic']]) || !is_string($s['status']) || !isset(AISA_Inbox::statuses()[$s['status']]) || !is_int($s['reviewer']) || $s['reviewer']<0 || !is_string($s['request_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$s['request_hash'])) { return false; }
        foreach (array('created','expires','updated','revision') as $key) { if (!is_int($s[$key]) || $s[$key]<1) { return false; } }
        if ($s['created']>time() || $s['expires']!==$s['created']+self::TTL || $s['updated']<$s['created'] || $s['updated']>time() || !is_array($s['events']) || count($s['events'])<1 || count($s['events'])>5 || (!$s['reviewer'] && $s['status']!=='open')) { return false; }
        foreach ($s['events'] as $event) { if (!is_array($event) || array_keys($event)!==array('at','status') || !is_int($event['at']) || $event['at']<$s['created'] || $event['at']>$s['updated'] || !is_string($event['status']) || !isset(AISA_Inbox::statuses()[$event['status']])) { return false; } }
        return $s;
    }
    private static function schedule() { if (!wp_next_scheduled(AISA_Inbox::HOOK)) { wp_schedule_single_event(time()+DAY_IN_SECONDS,AISA_Inbox::HOOK); } }
    private static function prune() {
        foreach (self::records() as $post) { if (AISA_Inbox::owner_pending($post)) { continue; } $s=self::state($post); if ((!$s || $s['expires']<=time()) && !wp_delete_post($post->ID,true)) { return false; } }
        return true;
    }
    private static function rates() {
        $stored=get_option(self::RATES,array());
        if (!is_array($stored) || count($stored)>self::LIMIT || strlen(wp_json_encode($stored))>16384) { return false; }
        $clean=array(); foreach ($stored as $uid=>$times) {
            if (!ctype_digit((string)$uid) || (int)$uid<1 || !is_array($times) || count($times)>5) { return false; }
            if (!get_userdata((int)$uid)) { continue; }
            foreach ($times as $at) { if (!is_int($at) || $at<1 || $at>time()) { return false; } if ($at>time()-DAY_IN_SECONDS) { $clean[$uid][]=$at; } }
        } return $clean;
    }
    public static function cleanup() {
        if (!add_option(AISA_Inbox::LOCK,time(),'','no')) { self::schedule(); return; }
        try {
            self::prune(); $rates=self::rates(); if ($rates!==false) { update_option(self::RATES,$rates,false); }
            if (self::records() || $rates) { self::schedule(); }
        } finally { delete_option(AISA_Inbox::LOCK); }
    }
    private static function summary($post,$s) {
        return array('id'=>(int)$post->ID,'topic'=>$s['topic'],'status'=>$s['status'],'created'=>$s['created'],'updated'=>$s['updated'],'expires'=>$s['expires'],'review_availability'=>!$s['reviewer']?'unassigned':(user_can($s['reviewer'],'manage_options')?'assigned':'unavailable'));
    }
    public static function status($r) {
        $p=self::input($r,array('scope')); if (is_wp_error($p)) { return $p; }
        $tickets=array(); foreach (self::records(get_current_user_id()) as $post) {
            if ((int)$post->post_author!==get_current_user_id()) { continue; }
            $s=self::state($post); if (!$s || $s['expires']<=time()) { continue; }
            $tickets[]=self::summary($post,$s); if (count($tickets)>=self::OWNER_LIMIT) { break; }
        }
        $response=rest_ensure_response(array('tickets'=>$tickets)); $response->header('Cache-Control','private, no-store'); return $response;
    }
    public static function submit($r) {
        $p=self::input($r,array('scope','topic','confirm','request_id')); if (is_wp_error($p)) { return $p; }
        if ($p['confirm']!==true || !is_string($p['topic']) || !isset(self::topics()[$p['topic']]) || !is_string($p['request_id']) || !preg_match('/^[a-f0-9]{32}$/D',$p['request_id'])) { return self::error('ticket_input','Choose a support topic and explicitly confirm submission.'); }
        if (!add_option(AISA_Inbox::LOCK,time(),'','no')) { return self::error('ticket_busy','Support review is busy. Try again later.',409); }
        try {
            if (!self::prune()) { return self::error('ticket_storage','Support requests could not be cleaned up.',503); }
            $hash=hash_hmac('sha256',AISA_Memory::scope().'|'.$p['request_id'],wp_salt('nonce'));
            $owned=array(); foreach (self::records(get_current_user_id()) as $post) {
                if ((int)$post->post_author!==get_current_user_id()) { continue; }
                $s=self::state($post); if (!$s || $s['expires']<=time()) { continue; }
                if (hash_equals($s['request_hash'],$hash)) {
                    if ($s['topic']!==$p['topic']) { return self::error('ticket_conflict','This submission ID was already used. Refresh your tickets.',409); }
                    $response=rest_ensure_response(array('ticket'=>self::summary($post,$s),'duplicate'=>true)); $response->header('Cache-Control','private, no-store'); return $response;
                } $owned[]=$post;
            }
            if (count($owned)>=self::OWNER_LIMIT || count(self::records())>=self::LIMIT) { return self::error('ticket_limit','The retained support ticket limit has been reached. Use the support contact options.',429); }
            $rates=self::rates(); $uid=get_current_user_id();
            if ($rates===false) { return self::error('ticket_storage','Support request limits are unavailable.',503); }
            if (count($rates[$uid]??array())>=5 || (!isset($rates[$uid]) && count($rates)>=self::LIMIT)) { return self::error('ticket_rate','Support submission limit reached. Please try later.',429); }
            $rates[$uid][]=time(); update_option(self::RATES,$rates,false);
            if (get_option(self::RATES)!==$rates) { return self::error('ticket_storage','Support request limits could not be saved.',503); }
            $now=time(); $s=array('site'=>get_current_blog_id(),'topic'=>$p['topic'],'status'=>'open','created'=>$now,'expires'=>$now+self::TTL,'updated'=>$now,'revision'=>1,'reviewer'=>0,'request_hash'=>$hash,'events'=>array(array('at'=>$now,'status'=>'open')));
            $id=wp_insert_post(wp_slash(array('post_type'=>self::TYPE,'post_status'=>'private','post_author'=>$uid,'post_title'=>'Account support: '.self::topics()[$p['topic']],'post_content'=>wp_json_encode($s),'meta_input'=>array(AISA_Inbox::OWNER_META=>$uid))),true);
            if (is_wp_error($id) || !$id || !($post=get_post($id)) || (int)$post->post_author!==$uid || self::state($post)!==$s) { return self::error('ticket_storage','Support request could not be saved. Refresh your tickets before retrying.',503); }
            self::schedule(); do_action('aisa_inbox_event','account request created');
            $response=rest_ensure_response(array('ticket'=>self::summary($post,$s),'duplicate'=>false)); $response->header('Cache-Control','private, no-store'); return $response;
        } finally { delete_option(AISA_Inbox::LOCK); }
    }
    public static function reviews() {
        if (!current_user_can('manage_options') || get_current_user_id()<1) { return array(); }
        $out=array(); foreach (self::records() as $post) {
            $s=self::state($post); if (!$s || $s['expires']<=time() || ($s['reviewer'] && $s['reviewer']!==get_current_user_id())) { continue; }
            $out[]=array('id'=>(int)$post->ID,'state'=>$s);
        } return $out;
    }
    public static function review($operation,$data) {
        if (!current_user_can('manage_options') || get_current_user_id()<1) { return self::error('ticket_forbidden','Support review is unavailable.',403); }
        if (!is_string($operation) || !in_array($operation,array('claim','status','delete'),true) || !is_array($data)) { return self::error('ticket_input','Invalid support review action.'); }
        foreach (array('id','revision') as $key) { if (!is_string($data[$key]??null) || !preg_match('/^[1-9][0-9]{0,9}$/D',$data[$key])) { return self::error('ticket_input','Invalid support ticket reference.'); } }
        if (!add_option(AISA_Inbox::LOCK,time(),'','no')) { return self::error('ticket_busy','Support review is busy. Try again later.',409); }
        try {
            $post=get_post((int)$data['id']); $s=self::state($post);
            if (!$s || $s['expires']<=time() || ($s['reviewer'] && $s['reviewer']!==get_current_user_id())) { return self::error('ticket_forbidden','Support ticket is unavailable.',403); }
            if ($s['revision']!==(int)$data['revision']) { return self::error('ticket_conflict','Ticket changed. Reload before reviewing.',409); }
            if ($operation==='claim') { if ($s['reviewer']===get_current_user_id()) { return array('notice'=>'claimed'); } $s['reviewer']=get_current_user_id(); $s['status']='in_review'; }
            else {
                if ($s['reviewer']!==get_current_user_id()) { return self::error('ticket_forbidden','Claim the support ticket before reviewing it.',403); }
                if ($operation==='delete') {
                    if (($data['confirm']??null)!=='delete_ticket') { return self::error('ticket_input','Confirm deletion of this support ticket.'); }
                    if (!wp_delete_post($post->ID,true)) { return self::error('ticket_storage','Support ticket could not be deleted.',503); }
                    do_action('aisa_inbox_event','account request deleted'); return array('notice'=>'deleted');
                }
                if (!is_string($data['status']??null) || !isset(AISA_Inbox::statuses()[$data['status']])) { return self::error('ticket_input','Choose a valid support review status.'); }
                if ($s['status']===$data['status']) { return array('notice'=>'status'); } $s['status']=$data['status'];
            }
            $s['updated']=time(); $s['revision']++; $s['events']=array_slice(array_merge($s['events'],array(array('at'=>$s['updated'],'status'=>$s['status']))),-5);
            $saved=wp_update_post(wp_slash(array('ID'=>$post->ID,'post_content'=>wp_json_encode($s))),true);
            if (is_wp_error($saved) || !$saved || self::state(get_post($post->ID))!==$s) { return self::error('ticket_storage','Support review could not be saved. Reload before retrying.',503); }
            do_action('aisa_inbox_event','account request reviewed'); return array('notice'=>$operation==='claim'?'claimed':'status');
        } finally { delete_option(AISA_Inbox::LOCK); }
    }
    public static function handle() {
        if (!current_user_can('manage_options') || get_current_user_id()<1) { wp_die('Not allowed','',array('response'=>403)); }
        check_admin_referer('aisa_admin'); $p=wp_unslash($_POST); $result=self::review($p['operation']??null,$p);
        if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()),'',array('response'=>$result->get_error_data()['status'])); }
        wp_safe_redirect(admin_url('admin.php?page=aisa&view=inbox&inbox_tab=handoff&ticket_notice='.$result['notice'].'#aisa-account-tickets')); exit;
    }
    private static function form($operation,$ticket) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aisa_request"><input type="hidden" name="operation" value="'.esc_attr($operation).'"><input type="hidden" name="id" value="'.(int)$ticket['id'].'"><input type="hidden" name="revision" value="'.(int)$ticket['state']['revision'].'">'; wp_nonce_field('aisa_admin');
    }
    public static function render() {
        if (!current_user_can('manage_options') || get_current_user_id()<1) { return; }
        $tickets=self::reviews(); $statuses=AISA_Inbox::statuses();
        echo '<div class="aisa-card" id="aisa-account-tickets"><div class="aisa-card-top"><h3>Account-linked support requests</h3><span class="aisa-badge">'.(AISA_Agent::settings()['customer_tickets']?'Enabled · staging unverified':'Intake disabled').'</span></div><p>Customers explicitly submit a fixed topic from their signed-in widget. Claim an unassigned request to review it; only its claimed administrator can change or delete it. Private local review tickets above remain separate.</p><p class="aisa-help">Stored: account ID, topic, status and timestamps; no transcript, contact details or order/download data. Three retained requests per account, 100 per site, five submissions per rolling 24 hours, seven-day absolute expiry. No notification, customer reply or refund is sent. Disabled intake also hides customer status access; staff may still review retained records and existing deadlines stay unchanged. Unavailable reviewers are not automatically replaced; customers see a paused-review notice.</p>';
        $notices=array('claimed'=>'Request claimed for review.','status'=>'Review status saved.','deleted'=>'Support request deleted.'); $notice=$_GET['ticket_notice']??'';
        if (is_string($notice) && isset($notices[$notice])) { echo '<p role="status" class="aisa-callout">'.esc_html($notices[$notice]).'</p>'; }
        echo '<div class="aisa-inbox-list">';
        foreach ($tickets as $ticket) {
            $s=$ticket['state']; echo '<article class="aisa-inbox-ticket"><div class="aisa-card-top"><h4>#'.(int)$ticket['id'].' · '.esc_html(self::topics()[$s['topic']]).'</h4><span class="aisa-badge">'.esc_html($statuses[$s['status']]).'</span></div><p class="aisa-help">'.($s['reviewer']?'Claimed by you':'Unassigned').' · Expires '.esc_html(gmdate('Y-m-d H:i',$s['expires'])).' UTC</p>';
            if (!$s['reviewer']) { self::form('claim',$ticket); echo '<button type="submit" class="button">Claim for review</button></form>'; }
            else {
                self::form('status',$ticket); echo '<label class="aisa-field"><span>Review status for account request #'.(int)$ticket['id'].'</span><select name="status">'; foreach ($statuses as $key=>$label) { echo '<option value="'.esc_attr($key).'" '.selected($s['status'],$key,false).'>'.esc_html($label).'</option>'; } echo '</select></label><button class="button" type="submit">Update review status</button></form>';
                echo '<details><summary>Delete this account request</summary>'; self::form('delete',$ticket); echo '<label><input name="confirm" type="checkbox" value="delete_ticket" required> Permanently delete this structured request</label><button class="button" type="submit">Delete request</button></form></details>';
            }
            echo '<details><summary>Recent review history</summary><ol>'; foreach ($s['events'] as $event) { echo '<li>'.esc_html(gmdate('Y-m-d H:i',$event['at']).' UTC · '.$statuses[$event['status']]).'</li>'; } echo '</ol></details></article>';
        }
        if (!$tickets) { echo '<p class="aisa-empty">No unassigned requests or requests claimed by you. This does not verify customer login or delivery.</p>'; }
        echo '</div></div>';
    }
}
