<?php
defined('ABSPATH') || exit;

/** Local administrator review only. Never collects conversations or dispatches notifications. */
final class AISA_Inbox {
    const TYPE='aisa_ticket';
    const SITE_LIMIT=100;
    const OWNER_LIMIT=25;
    const LOCK='aisa_inbox_lock';
    const HOOK='aisa_inbox_cleanup';
    const RETENTION='aisa_inbox_retention';
    public static function topics() { return array('general'=>'General support','catalog'=>'Product information','knowledge'=>'Knowledge review','orders'=>'Order access review','downloads'=>'Download access review'); }
    public static function statuses() { return array('open'=>'Open','in_review'=>'In review','resolved'=>'Resolved'); }
    public static function boot() {
        add_action('init',array(__CLASS__,'types'));
        add_action('admin_post_aisa_inbox',array(__CLASS__,'handle'));
        add_action(self::HOOK,array(__CLASS__,'cleanup'));
    }
    public static function types() {
        register_post_type(self::TYPE,array('public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_ui'=>false,'show_in_rest'=>false,'can_export'=>false,'delete_with_user'=>true,'supports'=>array()));
    }
    public static function retention() {
        $map=get_option(self::RETENTION,array()); $days=is_array($map)?(int)($map[get_current_user_id()]??7):7;
        return in_array($days,array(7,14,30),true)?$days:7;
    }
    private static function records($owner=0) {
        $args=array('post_type'=>self::TYPE,'post_status'=>'any','posts_per_page'=>self::SITE_LIMIT+1,'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true);
        if ($owner) { $args['author']=$owner; }
        return get_posts($args);
    }
    private static function state($post) {
        if (!$post || $post->post_type!==self::TYPE || $post->post_status!=='private' || strlen($post->post_content)>2048) { return false; }
        $s=json_decode($post->post_content,true,8);
        if (!is_array($s) || array_keys($s)!==array('site','topic','status','created','expires','updated','revision','events') || ($s['site']??null)!==get_current_blog_id() || !is_string($s['topic']) || !isset(self::topics()[$s['topic']]) || !is_string($s['status']) || !isset(self::statuses()[$s['status']])) { return false; }
        foreach (array('created','expires','updated','revision') as $key) { if (!is_int($s[$key]) || $s[$key]<1) { return false; } }
        if ($s['created']>time() || $s['updated']<$s['created'] || $s['updated']>time() || $s['expires']<=$s['created'] || $s['expires']>$s['created']+30*DAY_IN_SECONDS || !is_array($s['events']) || count($s['events'])<1 || count($s['events'])>5) { return false; }
        foreach ($s['events'] as $event) { if (!is_array($event) || array_keys($event)!==array('at','status') || !is_int($event['at']) || $event['at']<$s['created'] || $event['at']>$s['updated'] || !is_string($event['status']) || !isset(self::statuses()[$event['status']])) { return false; } }
        return $s;
    }
    public static function visible() {
        if (!current_user_can('manage_options') || get_current_user_id()<1) { return array(); }
        $out=array(); foreach (self::records(get_current_user_id()) as $post) {
            // Repeat the owner check even when the database query supplied an author constraint.
            if ((int)$post->post_author!==get_current_user_id()) { continue; }
            $s=self::state($post); if (!$s || $s['expires']<=time()) { continue; }
            $out[]=array('id'=>(int)$post->ID,'state'=>$s); if (count($out)>=self::OWNER_LIMIT) { break; }
        } return $out;
    }
    private static function error($code,$message,$status=400) { return new WP_Error($code,$message,array('status'=>$status)); }
    private static function lock() {
        // No timed takeover: an interrupted operation requires site maintenance to inspect the lock.
        // A slow worker must never overlap another writer merely because a lease elapsed.
        return add_option(self::LOCK,time(),'','no');
    }
    private static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) { wp_schedule_single_event(time()+DAY_IN_SECONDS,self::HOOK); }
    }
    private static function prune($owner=0,$all=false) {
        foreach (self::records($owner) as $post) {
            if ($owner && (int)$post->post_author!==$owner) { continue; }
            $s=self::state($post);
            if ($all || !$s || $s['expires']<=time()) {
                if (!wp_delete_post($post->ID,true)) { return false; }
            }
        } return true;
    }
    public static function cleanup() {
        if (!self::lock()) { self::schedule(); return; }
        try { self::prune(); if (self::records()) { self::schedule(); } }
        finally { delete_option(self::LOCK); }
    }
    public static function apply($operation,$data) {
        if (!current_user_can('manage_options') || get_current_user_id()<1) { return self::error('inbox_forbidden','Local inbox access is unavailable.',403); }
        if (!is_string($operation) || !in_array($operation,array('create','status','retention','purge'),true) || !is_array($data)) { return self::error('inbox_input','Invalid inbox action.'); }
        if (!self::lock()) { return self::error('inbox_busy','Inbox is busy. Try again.',409); }
        try {
            if ($operation==='retention') {
                if (!is_string($data['days']??null) || !in_array($data['days'],array('7','14','30'),true)) { return self::error('inbox_input','Choose 7, 14 or 30 days.'); }
                $map=get_option(self::RETENTION,array()); if (!is_array($map)) { $map=array(); }
                if (!isset($map[get_current_user_id()]) && count($map)>=self::SITE_LIMIT) { return self::error('inbox_full','Local retention preferences are full.',409); }
                $map[get_current_user_id()]=(int)$data['days']; update_option(self::RETENTION,$map,false);
                if (self::retention()!==(int)$data['days']) { return self::error('inbox_storage','Retention could not be saved.',503); }
                do_action('aisa_inbox_event','retention updated'); return array('notice'=>'retention');
            }
            if ($operation==='purge') {
                if (($data['confirm']??null)!=='delete_mine') { return self::error('inbox_input','Confirm deletion of your local tickets.'); }
                if (!self::prune(get_current_user_id(),true)) { return self::error('inbox_storage','Some local tickets could not be deleted.',503); }
                do_action('aisa_inbox_event','tickets purged'); return array('notice'=>'purged');
            }
            if ($operation==='create') {
                $topic=$data['topic']??null;
                if (!is_string($topic) || !isset(self::topics()[$topic])) { return self::error('inbox_input','Choose a support topic.'); }
                if (!self::prune()) { return self::error('inbox_storage','Expired tickets could not be cleaned up.',503); }
                if (count(self::records())>=self::SITE_LIMIT || count(self::records(get_current_user_id()))>=self::OWNER_LIMIT) { return self::error('inbox_full','Local inbox is full. Delete your old tickets before creating more.',409); }
                $now=time(); $s=array('site'=>get_current_blog_id(),'topic'=>$topic,'status'=>'open','created'=>$now,'expires'=>$now+self::retention()*DAY_IN_SECONDS,'updated'=>$now,'revision'=>1,'events'=>array(array('at'=>$now,'status'=>'open')));
                $id=wp_insert_post(wp_slash(array('post_type'=>self::TYPE,'post_status'=>'private','post_author'=>get_current_user_id(),'post_title'=>'Local review: '.self::topics()[$topic],'post_content'=>wp_json_encode($s))),true);
                if (is_wp_error($id) || !$id || !($post=get_post($id)) || (int)$post->post_author!==get_current_user_id() || self::state($post)!==$s) { return self::error('inbox_storage','Local ticket could not be saved.',503); }
                self::schedule(); do_action('aisa_inbox_event','created'); return array('notice'=>'created','id'=>(int)$id);
            }
            foreach (array('id','revision') as $key) { if (!is_string($data[$key]??null) || !preg_match('/^[1-9][0-9]{0,9}$/D',$data[$key])) { return self::error('inbox_input','Invalid ticket reference.'); } }
            $post=get_post((int)$data['id']);
            if (!$post || $post->post_type!==self::TYPE || (int)$post->post_author!==get_current_user_id()) { return self::error('inbox_forbidden','Local ticket is unavailable.',403); }
            $s=self::state($post);
            if (!$s || $s['expires']<=time()) { return self::error('inbox_expired','Local ticket has expired or is unavailable.',410); }
            if ($s['revision']!==(int)$data['revision']) { return self::error('inbox_conflict','Ticket changed. Reload before saving.',409); }
            $status=$data['status']??null;
            if (!is_string($status) || !isset(self::statuses()[$status])) { return self::error('inbox_input','Choose a valid review status.'); }
            if ($s['status']===$status) { return array('notice'=>'status'); }
            $s['status']=$status; $s['updated']=time(); $s['revision']++;
            $s['events']=array_slice(array_merge($s['events'],array(array('at'=>$s['updated'],'status'=>$status))),-5);
            $saved=wp_update_post(wp_slash(array('ID'=>$post->ID,'post_content'=>wp_json_encode($s))),true);
            if (is_wp_error($saved) || !$saved || self::state(get_post($post->ID))!==$s) { return self::error('inbox_storage','Status could not be saved. Reload before retrying.',503); }
            do_action('aisa_inbox_event','status updated'); return array('notice'=>'status');
        } finally { delete_option(self::LOCK); }
    }
    public static function handle() {
        if (!current_user_can('manage_options') || get_current_user_id()<1) { wp_die('Not allowed','',array('response'=>403)); }
        check_admin_referer('aisa_admin');
        $p=wp_unslash($_POST); $result=self::apply($p['operation']??null,$p);
        if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()),'',array('response'=>$result->get_error_data()['status'])); }
        wp_safe_redirect(admin_url('admin.php?page=aisa&inbox_notice='.$result['notice'].'#aisa-inbox')); exit;
    }
    private static function form($operation) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aisa_inbox"><input type="hidden" name="operation" value="'.esc_attr($operation).'">'; wp_nonce_field('aisa_admin');
    }
    public static function render() {
        if (!current_user_can('manage_options') || get_current_user_id()<1) { return; }
        $tickets=self::visible(); $statuses=self::statuses(); $scheduled=wp_next_scheduled(self::HOOK);
        echo '<div class="aisa-card" id="aisa-inbox"><div class="aisa-card-top"><h3>Private local handoff inbox</h3><span class="aisa-badge aisa-badge-muted">Local review only</span></div><p>Review structured support topics here. Only the administrator who creates a ticket can see or change it. The widget does not submit to this inbox; no customer reply, notification or refund is sent.</p><p class="aisa-help">No free-text messages, customer details, order IDs, download links or credentials are collected. Up to 25 tickets per administrator and 100 per site; five recent status events per ticket.</p>';
        $notices=array('created'=>'Local ticket created.','status'=>'Review status saved.','retention'=>'Expiry for new tickets saved. Existing deadlines are unchanged.','purged'=>'Your local tickets were deleted.');
        $notice=$_GET['inbox_notice']??''; if (is_string($notice) && isset($notices[$notice])) { echo '<p role="status" class="aisa-callout">'.esc_html($notices[$notice]).'</p>'; }
        echo '<p class="aisa-help">Cleanup schedule: <strong>'.($scheduled?'Scheduled · execution unverified':'Not scheduled').'</strong>. No schedule is created by opening this panel.</p>';
        echo '<div class="aisa-field-grid aisa-inbox-controls">'; self::form('create');
        echo '<label class="aisa-field"><span>Support topic</span><select name="topic">'; foreach (self::topics() as $key=>$label) { echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>'; }
        echo '</select></label><button class="button" type="submit">Create local review ticket</button></form>'; self::form('retention');
        echo '<label class="aisa-field"><span>Keep new tickets for</span><select name="days">'; foreach (array(7,14,30) as $days) { echo '<option value="'.(int)$days.'" '.selected(self::retention(),$days,false).'>'.(int)$days.' days</option>'; }
        echo '</select></label><button class="button" type="submit">Save retention</button></form></div><p class="aisa-help">Expiry is absolute and does not extend on activity. Retention changes apply to new tickets only. Expired tickets are hidden immediately; physical deletion runs on ticket creation or the daily cleanup event when WordPress cron executes.</p><div class="aisa-inbox-list">';
        foreach ($tickets as $ticket) {
            $s=$ticket['state']; echo '<article class="aisa-inbox-ticket"><div class="aisa-card-top"><h4>#'.(int)$ticket['id'].' · '.esc_html(self::topics()[$s['topic']]).'</h4><span class="aisa-badge">'.esc_html($statuses[$s['status']]).'</span></div><p class="aisa-help">Created '.esc_html(gmdate('Y-m-d H:i',$s['created'])).' UTC · Expires '.esc_html(gmdate('Y-m-d H:i',$s['expires'])).' UTC</p>';
            self::form('status'); echo '<input type="hidden" name="id" value="'.(int)$ticket['id'].'"><input type="hidden" name="revision" value="'.(int)$s['revision'].'"><label class="aisa-field"><span>Review status for ticket #'.(int)$ticket['id'].'</span><select name="status">';
            foreach ($statuses as $key=>$label) { echo '<option value="'.esc_attr($key).'" '.selected($s['status'],$key,false).'>'.esc_html($label).'</option>'; }
            echo '</select></label><button class="button" type="submit">Update status</button></form><details><summary>Recent status history</summary><ol>'; foreach ($s['events'] as $event) { echo '<li>'.esc_html(gmdate('Y-m-d H:i',$event['at']).' UTC · '.$statuses[$event['status']]).'</li>'; } echo '</ol></details></article>';
        }
        if (!$tickets) { echo '<p class="aisa-empty">No active local tickets. This is not a connected customer inbox.</p>'; }
        echo '</div><details class="aisa-verification"><summary>Delete my local tickets</summary>'; self::form('purge'); echo '<label><input type="checkbox" name="confirm" value="delete_mine" required> Permanently delete all my local tickets on this site</label><p><button class="button" type="submit">Delete my tickets</button></p></form></details></div>';
    }
}
