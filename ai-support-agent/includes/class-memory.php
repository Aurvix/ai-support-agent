<?php
defined('ABSPATH') || exit;

/** Public reference history only: no questions, answers, URLs or credentials are stored. */
final class AISA_Memory {
    const TTL=900;
    const TURNS=4;
    const REFERENCES=8;
    const BYTES=2048; // Conservative byte-per-token budget units; not a vendor tokenizer.
    const STATE_BYTES=8192;
    public static function scope() {
        return hash_hmac('sha256',get_current_blog_id().'|'.get_current_user_id().'|'.wp_get_session_token().'|'.(int)get_option('aisa_memory_epoch',0),wp_salt('nonce'));
    }
    public static function validate($scope) {
        // Do not elevate a cookie-authenticated user stripped by REST for a missing nonce.
        $cookie_user=wp_validate_auth_cookie('','logged_in');
        if (($cookie_user && (int)$cookie_user!==get_current_user_id()) || !is_string($scope) || !hash_equals(self::scope(),$scope)) {
            return new WP_Error('identity_changed','Your sign-in or support settings changed. Reload to start a fresh conversation.',array('status'=>409));
        }
        return true;
    }
    public static function open($session,$scope) {
        $valid=self::validate($scope); if (is_wp_error($valid)) { return $valid; }
        if (!is_string($session) || !preg_match('/^[a-f0-9]{32}$/D',$session)) { return new WP_Error('memory_session','Start a fresh support session.',array('status'=>400)); }
        $key='aisa_mem_'.hash_hmac('sha256',self::scope().'|'.$session,wp_salt('nonce'));
        if (get_transient($key.'_reset')) { return new WP_Error('memory_reset','This conversation was cleared. Start a fresh session.',array('status'=>409)); }
        $now=time(); $state=get_transient($key); $expired=false;
        if (is_array($state) && is_int($state['expires']??null) && $state['expires']<=$now) { delete_transient($key); $state=false; $expired=true; }
        if (!self::valid_state($state,$now)) {
            delete_transient($key);
            $state=array('born'=>$now,'expires'=>$now+self::TTL,'turns'=>array());
        }
        $turns=array();
        foreach (array_slice($state['turns'],-self::TURNS) as $turn) {
            if (!is_array($turn) || !isset($turn['at'],$turn['refs']) || !is_int($turn['at']) || $turn['at']<$state['born'] || $turn['at']>$now) { continue; }
            $turns[]=array('at'=>$turn['at'],'refs'=>self::clean_refs($turn['refs']));
        }
        $state['turns']=$turns;
        return array('key'=>$key,'scope'=>self::scope(),'state'=>$state,'expired'=>$expired);
    }
    private static function clean_refs($refs) {
        $clean=array(); if (!is_array($refs)) { return $clean; }
        foreach (array_slice($refs,0,self::REFERENCES) as $ref) {
            if (!is_array($ref) || !is_int($ref['id']??null) || $ref['id']<1 || !in_array($ref['kind']??'',array('knowledge','product'),true) || !is_string($ref['stamp']??null) || !preg_match('/^[a-f0-9]{64}$/D',$ref['stamp'])) { continue; }
            $clean[$ref['kind'].'|'.$ref['id']]=array('id'=>$ref['id'],'kind'=>$ref['kind'],'stamp'=>$ref['stamp']);
        }
        return array_values($clean);
    }
    private static function valid_state($state,$now) {
        return is_array($state) && is_int($state['born']??null) && is_int($state['expires']??null) && is_array($state['turns']??null) && $state['born']<=$now && $state['expires']>$now && $state['expires']<=$state['born']+self::TTL && strlen(wp_json_encode($state))<=self::STATE_BYTES;
    }
    public static function references($context) {
        $refs=array();
        foreach (array_reverse($context['state']['turns']) as $turn) { foreach ($turn['refs'] as $ref) { $key=$ref['kind'].'|'.$ref['id']; if (!isset($refs[$key])) { $refs[$key]=$ref; } } }
        return array_slice(array_values($refs),0,self::REFERENCES);
    }
    public static function commit($context,$refs) {
        $key=$context['key']; $now=time();
        if (!hash_equals(self::scope(),$context['scope']) || get_transient($key.'_reset') || $context['state']['expires']<=$now) { return false; }
        if (!add_option($key.'_lock',$now,'','no')) { if ((int)get_option($key.'_lock')<$now-10) { delete_option($key.'_lock'); } return false; }
        try {
            if (get_transient($key.'_reset')) { return false; }
            $state=get_transient($key); if (!self::valid_state($state,$now)) { $state=$context['state']; }
            if (!isset($state['born'],$state['expires'],$state['turns']) || $state['expires']<=$now || !is_array($state['turns'])) { return false; }
            $state['turns'][]=array('at'=>$now,'refs'=>self::clean_refs($refs));
            $state['turns']=array_slice($state['turns'],-self::TURNS);
            $turns=array(); foreach ($state['turns'] as $turn) { if (is_array($turn) && is_int($turn['at']??null) && $turn['at']>=$state['born'] && $turn['at']<=$now) { $turns[]=array('at'=>$turn['at'],'refs'=>self::clean_refs($turn['refs']??array())); } }
            $state=array('born'=>(int)$state['born'],'expires'=>min((int)$state['expires'],$context['state']['expires']),'turns'=>$turns);
            if (strlen(wp_json_encode($state))>self::STATE_BYTES) { return false; }
            set_transient($key,$state,max(1,$state['expires']-$now));
            if (get_transient($key.'_reset')) { delete_transient($key); return false; }
            return get_transient($key)===$state;
        } finally { delete_option($key.'_lock'); }
    }
    public static function clear($context) {
        set_transient($context['key'].'_reset',1,self::TTL*2);
        if (!get_transient($context['key'].'_reset')) { return false; }
        delete_transient($context['key']);
        return get_transient($context['key'])===false;
    }
}
