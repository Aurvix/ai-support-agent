<?php
defined('ABSPATH') || exit;

/** Reviewed offline model names/IDs. Account access is never inferred from this catalog. */
final class AISA_Provider {
    const OBSERVATION = 'aisa_model_observation';
    const PROBE_LIMIT = 'aisa_model_probe_until';
    const REVIEWED = '2026-11-10';
    public static function catalog() {
        // Keep existing automatic resolutions stable across this UI upgrade.
        return array(
            'openai'=>array('model'=>'gpt-4.1-mini','endpoint'=>'https://api.openai.com/v1/chat/completions'),
            'gemini'=>array('model'=>'gemini-3.1-flash-lite','endpoint'=>'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'),
            'nvidia'=>array('model'=>'meta/llama-3.3-70b-instruct','endpoint'=>'https://integrate.api.nvidia.com/v1/chat/completions'),
            'groq'=>array('model'=>'llama-3.3-70b-versatile','endpoint'=>'https://api.groq.com/openai/v1/chat/completions')
        );
    }
    public static function models($provider) {
        $models=array(
            'openai'=>array(
                'gpt-6-luna'=>array('name'=>'GPT-6 Luna','availability'=>'Documented; account access not tested'),
                'gpt-6.1-terra'=>array('name'=>'GPT-6.1 Terra','availability'=>'Documented; account access not tested'),
                'gpt-6.1-sol'=>array('name'=>'GPT-6.1 Sol','availability'=>'Documented; account access not tested'),
                'gpt-4.1-mini'=>array('name'=>'GPT-4.1 Mini','availability'=>'Deprecated in provider documentation; existing choice retained')
            ),
            'gemini'=>array(
                'gemini-3.1-flash-lite'=>array('name'=>'Gemini 3.1 Flash-Lite','availability'=>'Stable listing; account access not tested'),
                'gemini-3.5-flash-lite'=>array('name'=>'Gemini 3.5 Flash-Lite','availability'=>'Stable listing; account access not tested'),
                'gemini-3.8-flash'=>array('name'=>'Gemini 3.8 Flash','availability'=>'Stable listing; account access not tested')
            ),
            'nvidia'=>array(
                'nvidia/nemotron-3-nano-30b-a3b'=>array('name'=>'NVIDIA Nemotron 3 Nano 30B A3B','availability'=>'Documented NIM model; hosted access not tested'),
                'nvidia/nemotron-3.5-bolt-e4b'=>array('name'=>'NVIDIA Nemotron 3.5 Bolt E4B','availability'=>'Documented NIM model; hosted access not tested'),
                'meta/llama-3.3-70b-instruct'=>array('name'=>'Meta Llama 3.3 70B Instruct','availability'=>'Free endpoint deprecated; partner access is separate and not tested')
            ),
            'groq'=>array(
                'openai/gpt-oss-20b'=>array('name'=>'OpenAI GPT-OSS 20B on Groq','availability'=>'Production listing; account access not tested'),
                'openai/gpt-oss-120b'=>array('name'=>'OpenAI GPT-OSS 120B on Groq','availability'=>'Production listing; account access not tested'),
                'llama-3.3-70b-versatile'=>array('name'=>'Llama 3.3 70B Versatile','availability'=>'Enterprise only; free/developer access retired 2026-08-16')
            )
        );
        return $models[$provider]??array();
    }
    public static function ui_catalog() {
        $catalog=self::catalog(); foreach ($catalog as $provider=>&$preset) { $preset['models']=self::models($provider); } unset($preset); return $catalog;
    }
    public static function choose($provider,$choice,$manual) {
        if (!is_string($choice)) { return new WP_Error('model_choice','Choose a model from this provider or use Manual override.'); }
        if ($choice==='__auto__' && isset(self::catalog()[$provider])) { return array('model_mode'=>'auto'); }
        if ($choice==='__manual__') {
            if (!is_string($manual) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/@+\-]{0,199}$/D',trim($manual)) || str_contains($manual,'://')) { return new WP_Error('model_choice','Enter an exact model ID of 1–200 characters, without spaces or a URL.'); }
            return array('model_mode'=>'manual','model'=>trim($manual));
        }
        if (isset(self::models($provider)[$choice])) { return array('model_mode'=>'preset','model'=>$choice); }
        return new WP_Error('model_choice','That model is not listed for this provider. Reload or use Manual override.');
    }
    public static function resolve($s) {
        $preset=self::catalog()[$s['provider']]??null;
        return array('model'=>($s['model_mode']??'manual')==='auto'?($preset['model']??''):trim((string)$s['model']),
            'endpoint'=>$preset?$preset['endpoint']:(in_array($s['provider'],array('local','compatible'),true)?$s['endpoint']:''));
    }
    public static function availability($provider,$model) { return self::models($provider)[$model]['availability']??'Manual/gateway model; availability not checked'; }
    public static function scope($s) {
        $target=self::resolve($s); return hash('sha256',$s['provider'].'|'.$target['endpoint']);
    }
    public static function matches($s) { return is_string($s['secret_scope']??null) && hash_equals(self::scope($s),$s['secret_scope']); }
    private static function signature($s) {
        return hash_hmac('sha256',self::scope($s).'|'.self::resolve($s)['model'].'|'.hash('sha256',(string)$s['secret']),wp_salt('auth'));
    }
    public static function api_status($response) {
        $body=wp_remote_retrieve_body($response); if (!is_string($body) || strlen($body)>65536) { return ''; }
        $json=json_decode($body,true,16); $error=is_array($json)?($json['error']??null):null; $status=is_array($error)?($error['status']??null):null;
        return is_string($status) && in_array($status,array('INTERNAL','UNAVAILABLE','INVALID_ARGUMENT','NOT_FOUND','PERMISSION_DENIED','UNAUTHENTICATED','RESOURCE_EXHAUSTED','FAILED_PRECONDITION','DEADLINE_EXCEEDED'),true)?$status:'';
    }
    public static function record($s,$category,$http=0,$api='',$synthetic=false) {
        $categories=array('testing','ok','setup','endpoint','transport','authentication','quota','model_or_endpoint_unavailable','upstream','http','response_size','response_format','unsupported_answer','incomplete_answer','answer_format','empty_answer');
        if (!in_array($category,$categories,true)) { $category='http'; }
        if (!in_array($api,array('INTERNAL','UNAVAILABLE','INVALID_ARGUMENT','NOT_FOUND','PERMISSION_DENIED','UNAUTHENTICATED','RESOURCE_EXHAUSTED','FAILED_PRECONDITION','DEADLINE_EXCEEDED'),true)) { $api=''; }
        $data=array('signature'=>self::signature($s),'category'=>$category,'http'=>is_int($http)&&$http>=100&&$http<=599?$http:0,'api'=>$api,'at'=>time(),'synthetic'=>(bool)$synthetic);
        set_transient(self::OBSERVATION,$data,HOUR_IN_SECONDS);
        return get_transient(self::OBSERVATION)===$data;
    }
    public static function observation($s) {
        $data=get_transient(self::OBSERVATION);
        if (!is_array($data) || !is_string($data['signature']??null) || !hash_equals(self::signature($s),$data['signature']) || !is_int($data['at']??null) || $data['at']<time()-HOUR_IN_SECONDS || $data['at']>time()+60) { return null; }
        // Never return the internal binding, ciphertext, model ID, response, prompt or headers.
        $allowed=array('testing','ok','setup','endpoint','transport','authentication','quota','model_or_endpoint_unavailable','upstream','http','response_size','response_format','unsupported_answer','incomplete_answer','answer_format','empty_answer');
        $category=is_string($data['category']??null) && in_array($data['category'],$allowed,true)?$data['category']:'http';
        $api=is_string($data['api']??null) && in_array($data['api'],array('INTERNAL','UNAVAILABLE','INVALID_ARGUMENT','NOT_FOUND','PERMISSION_DENIED','UNAUTHENTICATED','RESOURCE_EXHAUSTED','FAILED_PRECONDITION','DEADLINE_EXCEEDED'),true)?$data['api']:'';
        return array('category'=>$category,'http'=>is_int($data['http']??null)&&$data['http']>=100&&$data['http']<=599?$data['http']:0,'api'=>$api,'at'=>$data['at'],'synthetic'=>!empty($data['synthetic']));
    }
    public static function guidance($category) {
        return array('testing'=>'Test started; no complete response recorded yet.','ok'=>'A complete text response was received. This does not verify every answer, model or customer flow.','setup'=>'Saved model or credential binding needs review. The saved key has not been changed.','endpoint'=>'A public secure endpoint is required. No request was sent.','transport'=>'Network/timeout failure. The upstream request may still have been processed; no automatic retry.','authentication'=>'Provider rejected access. Check account permissions privately; do not paste the key into logs or chat.','quota'=>'Provider quota/rate limit. Check the provider account or wait; no automatic retry or purchase.','model_or_endpoint_unavailable'=>'Model or endpoint was not found. Select the exact provider model ID and verify account access.','upstream'=>'The configured service returned a server error. Raw response text is hidden; no automatic retry.','http'=>'The service rejected the request. Check model compatibility and the sanitized HTTP status.','incomplete_answer'=>'The response was incomplete. No partial answer is shown; quota may have been consumed.','unsupported_answer'=>'The service returned a refused/tool/non-text response that this widget cannot use.')[$category]??'No usable complete text response was received. Raw upstream text is hidden; no automatic retry.';
    }
}
