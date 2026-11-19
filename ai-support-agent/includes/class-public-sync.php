<?php
defined('ABSPATH') || exit;

/** Explicit public-reference authorization. No network crawl, uploads, private data or model training. */
final class AISA_Public_Sync {
    const HOLD='_aisa_public_sync_hold'; const ALLOWED='_aisa_public_sync_allowed'; const STATE='aisa_sync_state'; const DAILY='aisa_public_daily'; const BATCH='aisa_public_batch'; const LOCK='aisa_sync_lock';
    public static function boot() { add_action('init',array(__CLASS__,'schedule')); add_action(self::DAILY,array(__CLASS__,'start')); add_action(self::BATCH,array(__CLASS__,'run')); }
    public static function enabled() { return !empty(AISA_Agent::settings()['public_sync']); }
    private static function configured() { $state=get_option(self::STATE); return self::enabled() && self::types() && is_array($state) && isset($state['floor'],$state['source_floor']) && ($state['types']??null)===self::types() && (int)($state['reference_policy']??0)===(int)!empty(AISA_Agent::settings()['public_reference']) && ($state['status']??'')!=='disabled'; }
    public static function all_public() { return self::configured() && !empty(AISA_Agent::settings()['public_reference']); }
    public static function types() { return array_values(array_intersect(array('post','page','product'),(array)AISA_Agent::settings()['public_sync_types'])); }
    public static function permitted($id,$scoped=false) {
        $p=get_post($id); if(!$p || !in_array($p->post_type,array('post','page','product'),true) || $p->post_status!=='publish' || $p->post_password!=='' || !is_post_publicly_viewable($p) || ($scoped && !in_array($p->post_type,self::types(),true))) return false;
        foreach(array('woocommerce_myaccount_page_id','woocommerce_cart_page_id','woocommerce_checkout_page_id')as$key){if((int)get_option($key) === (int)$id)return false;}
        // Customer-service destinations are never knowledge, including theme wishlist pages.
        $path=trim((string)wp_parse_url(get_permalink($id),PHP_URL_PATH),'/');
        if(preg_match('~(?:^|/)(?:my-account|account|cart|checkout|wishlist|compare)(?:/|$)~i',$path) || in_array(strtolower($p->post_name),array('my-account','account','cart','checkout','wishlist','compare'),true))return false;
        foreach(array('yith_wcwl_wishlist_page_id','yith_wcwl_wishlist_page')as$key){if((int)get_option($key)===(int)$id)return false;}
        // Dynamic/account shortcodes and dynamic Woo blocks need manual review; never execute them here.
        if(preg_match('/\[(?:[A-Za-z_][A-Za-z0-9_-]*)(?:\s|\]|\/)|<!--\s+wp:woocommerce\//i',$p->post_content))return false;
        $public_text=$p->post_title.'\n'.$p->post_content;
        if($p->post_type==='product'){$product=AISA_Agent::public_product_record($id);if(!$product)return false;$public_text.='\n'.$product->get_short_description();}
        if(preg_match('/-----BEGIN[^\r\n]*PRIVATE KEY|(?:api[_ -]?key|password|secret|access[_ -]?token)\s*[:=]\s*["\x27]?[A-Za-z0-9_\/+=.-]{12,}/i',$public_text))return false;
        if(preg_match('/(?:[?&]|&amp;)(?:download_file|order_id|order_key|_wpnonce|access_token|token|key|email)=/i',$public_text))return false;
        if($p->post_type==='product' && !AISA_Agent::public_product_record($id))return false;
        return AISA_Agent::public_store_url(get_permalink($id)) && (bool)apply_filters('aisa_public_sync_allowed',true,(int)$id);
    }
    public static function configure($old,$s) {
        $changed=(int)$old['public_sync']!==(int)$s['public_sync'] || $old['public_sync_types']!==$s['public_sync_types'] || (int)($old['public_reference']??0)!==(int)($s['public_reference']??0); if(!$changed)return;
        wp_clear_scheduled_hook(self::DAILY); wp_clear_scheduled_hook(self::BATCH);
        if(!$s['public_sync']) { $state=(array)get_option(self::STATE,array()); $state['status']='disabled'; update_option(self::STATE,$state,false);return; }
        global $wpdb;$wpdb->last_error=''; $floor=(int)$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='aisa_knowledge'");if($wpdb->last_error)wp_die('Public sync source boundary could not be verified.','',array('response'=>503));$source_floor=(int)$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");if($wpdb->last_error || $source_floor<$floor)wp_die('Public sync source boundary could not be verified.','',array('response'=>503));
        $state=array('floor'=>$floor,'source_floor'=>$source_floor,'types'=>self::types(),'reference_policy'=>(int)!empty($s['public_reference']),'status'=>'queued','cursor'=>0,'phase'=>'sources','ceiling'=>0,'batches'=>0,'processed'=>0,'last_started'=>0,'last_completed'=>0,'last_batch'=>0,'error'=>'');
        update_option(self::STATE,$state,false);
        if(get_option(self::STATE)!==$state) { wp_die('Public sync setup could not be verified. Disable it and retry after checking storage.','',array('response'=>503)); }
        self::schedule(); if(!wp_next_scheduled(self::BATCH))wp_schedule_single_event(time()+15,self::BATCH);
    }
    public static function migrate() {
        if(!self::enabled())return;$state=get_option(self::STATE);if(!is_array($state)||!isset($state['floor'])||isset($state['source_floor']))return;
        // Add a stable source boundary to the existing explicit policy; no new scope or approval.
        global $wpdb;$wpdb->last_error='';$ceiling=(int)$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");if($wpdb->last_error || $ceiling<(int)$state['floor'])return;$state['source_floor']=$ceiling;update_option(self::STATE,$state,false);
    }
    public static function schedule() { self::migrate(); if(self::configured() && !wp_next_scheduled(self::DAILY))wp_schedule_event(time()+DAY_IN_SECONDS,'daily',self::DAILY); }
    public static function start() {
        if(!self::configured())return; $state=(array)get_option(self::STATE,array());if(!array_key_exists('floor',$state))return;
        if(in_array($state['status']??'',array('running','queued'),true)) { if(!wp_next_scheduled(self::BATCH))wp_schedule_single_event(time()+15,self::BATCH);return; }
        $state['status']='queued';$state['batches']=0;$state['error']='';
        if(($state['phase']??'')==='complete'){$state['phase']='sources';$state['cursor']=0;$state['ceiling']=0;$state['processed']=0;}
        update_option(self::STATE,$state,false);if(get_option(self::STATE)===$state && !wp_next_scheduled(self::BATCH))wp_schedule_single_event(time()+15,self::BATCH);
    }
    public static function stamp($id) {
        if(!self::configured())return; $d=get_post($id); $source=(int)get_post_meta($id,'aisa_source',true);$state=get_option(self::STATE);
        if(!$d || !is_array($state) || !isset($state['floor']) || !self::permitted($source,true) || get_post_meta($source,self::HOLD,true))return;
        // Global public-reference consent must not mint durable per-source legacy consent.
        if(self::all_public())return;
        // Legacy mode preserves existing pending sources until individually allowed.
        $eligible=$source>(int)$state['source_floor'] || get_post_meta($source,self::ALLOWED,true)==='1' || get_post_meta($id,'aisa_approved_hash',true)!=='' || get_post_meta($id,'aisa_auto_eligible',true)==='1';
        if(!$eligible)return;
        update_post_meta($source,self::ALLOWED,'1');if(get_post_meta($source,self::ALLOWED,true)!=='1')throw new RuntimeException('sync_storage');
        update_post_meta($id,'aisa_auto_eligible','1'); update_post_meta($id,'aisa_auto_hash',AISA_Agent::review_signature($d));
        if(get_post_meta($id,'aisa_auto_hash',true)!==AISA_Agent::review_signature($d))throw new RuntimeException('sync_storage');
    }
    public static function approved($d) {
        if(!self::configured() || !self::permitted((int)get_post_meta($d->ID,'aisa_source',true),true) || get_post_meta((int)get_post_meta($d->ID,'aisa_source',true),self::HOLD,true))return false;
        if(self::all_public())return AISA_Agent::source_is_current($d);
        $hash=get_post_meta($d->ID,'aisa_auto_hash',true);return is_string($hash) && $hash!=='' && hash_equals(AISA_Agent::review_signature($d),$hash);
    }
    public static function hold($source) { if($source){update_post_meta($source,self::HOLD,'1');if(get_post_meta($source,self::HOLD,true)!=='1')wp_die('Source hold could not be saved. No removal/revocation was performed.','',array('response'=>503));} }
    public static function release($source) { if($source){delete_post_meta($source,self::HOLD);if(get_post_meta($source,self::HOLD,true)!=='')wp_die('Source hold could not be cleared. Reload and retry.','',array('response'=>503));} }
    public static function run() {
        if(!self::configured())return; $state=get_option(self::STATE); if(!is_array($state) || !isset($state['floor']) || in_array($state['status']??'',array('disabled','limit','complete'),true))return;
        $lock=(int)get_option(self::LOCK);if($lock && $lock<time()-300){delete_option(self::LOCK);return;}if(!add_option(self::LOCK,time(),'','no'))return;
        try {
            global $wpdb; $state['status']='running';$state['error']='';
            if(empty($state['ceiling'])){$state['ceiling']=(int)$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");$state['last_started']=time();}
            $phase=($state['phase']??'sources')==='prune'?'prune':'sources';$types=$phase==='prune'?"'aisa_knowledge'":"'".implode("','",self::types())."'";
            $wpdb->last_error='';$ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND ID<=%d AND post_type IN ($types) ORDER BY ID ASC LIMIT 50",(int)$state['cursor'],(int)$state['ceiling']));
            if($wpdb->last_error || !is_array($ids))throw new RuntimeException('sync_storage');
            foreach($ids as$id){$source=$phase==='prune'?(int)get_post_meta($id,'aisa_source',true):(int)$id;if($source && AISA_Agent::sync($source)===false)throw new RuntimeException('sync_storage');$state['cursor']=(int)$id;$state['processed']++;}
            $state['batches']++;$state['last_batch']=time();
            if(count($ids)<50){if($phase==='sources'){$state['phase']='prune';$state['cursor']=0;$state['ceiling']=(int)$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='aisa_knowledge'");}else{$state['phase']='complete';$state['status']='complete';$state['last_completed']=time();}}
            if($state['status']!=='complete' && $state['batches']>=100){$state['status']='limit';$state['error']='daily_batch_limit';}
            update_option(self::STATE,$state,false);if(get_option(self::STATE)!==$state)throw new RuntimeException('sync_storage');
            if($state['status']==='running' && !wp_next_scheduled(self::BATCH))wp_schedule_single_event(time()+30,self::BATCH);
        } catch(Throwable $e){$state['status']='error';$state['error']='storage_or_job_failure';$state['last_batch']=time();update_option(self::STATE,$state,false);}
        finally { delete_option(self::LOCK); }
    }
    public static function render($s) {
        $state=(array)get_option(self::STATE,array());$enabled=!empty($s['public_sync']);
        echo '<div class="aisa-card"><h3>Daily public knowledge sync</h3><p>Explicit site-owner permission for selected ordinary public reference content. This does not train model weights. Files, teaching entries and customer suggestions still require manual approval. The existing-source option below intentionally includes previously pending ordinary public website references. Explicit exclusions remain held. Public availability is not a guarantee of accuracy.</p><input form="aisa-settings" type="hidden" name="public_sync_present" value="1"><label><input form="aisa-settings" type="checkbox" name="public_sync" value="1" '.checked($enabled,true,false).'> Allow public content auto-sync and use without repeated approval</label><div class="aisa-sync-types">';
        foreach(array('post'=>'Published posts','page'=>'Public pages','product'=>'Public catalog products')as$type=>$label){echo '<label><input form="aisa-settings" type="checkbox" name="public_sync_types[]" value="'.esc_attr($type).'" '.checked(in_array($type,$s['public_sync_types'],true),true,false).'> '.esc_html($label).'</label>';}
        echo '</div><input form="aisa-settings" type="hidden" name="public_reference_present" value="1"><label><input form="aisa-settings" type="checkbox" name="public_reference" value="1" '.checked(!empty($s['public_reference']),true,false).'> Include existing ordinary public website sources automatically</label><p class="aisa-help">With this option, public posts/pages (including the homepage) and catalog products in the selected scope need no individual approval. Explicitly excluded sources stay excluded until restored. Uploads, admin teaching and suggestions still need manual review. Changing this policy queues a fresh bounded sync.</p><details><summary>Public-reference safety and timing</summary><p class="aisa-help">Fresh installs automatically include public WooCommerce products; existing saved choices are preserved. Excludes protected/private/draft content, accounts, checkout/cart, dynamic shortcodes, uploads and customer/order/session data. Public content is untrusted reference, never an instruction or permission. Membership/restriction plugins need an explicit exclusion via aisa_public_sync_allowed and staging verification; published status alone cannot certify third-party access rules. Eligible public references may be sent to your configured model when relevant. Revocation/removal holds survive sync; disabling this mode immediately stops auto-authorized retrieval. Manually approved entries retain their separate approval.</p><p><strong>'.esc_html($enabled?(self::configured()?ucfirst($state['status']??'not scheduled'):'Needs configuration/storage check'):'Disabled').'</strong> · '.esc_html((int)($state['processed']??0)).' records visited this cycle</p>';
        foreach(array('last_started'=>'Cycle started','last_batch'=>'Last batch','last_completed'=>'Last full sync')as$key=>$label){echo '<p>'.esc_html($label).': '.esc_html(!empty($state[$key])?gmdate('Y-m-d H:i:s',(int)$state[$key]).' UTC':'Not completed').'</p>';}
        echo '<p>Next daily opportunity: '.esc_html(wp_next_scheduled(self::DAILY)?gmdate('Y-m-d H:i:s',wp_next_scheduled(self::DAILY)).' UTC':'Not scheduled').'</p><p>Error: '.esc_html($state['error']??'' ?: 'None recorded').'</p><p class="aisa-help">WP-Cron depends on site visits or host cron: daily is an opportunity, not an exact clock promise. Up to 50 records per batch / 100 batches per daily cycle; oversized cycles continue next day. Deletions and public-access/freshness changes are rechecked on every answer even if cron is late. Storage errors are reported without raw content or secrets.</p></details></div>';
    }
}
