<?php
defined('ABSPATH') || exit;

/** Bounded, stateless JSON-response transport. No new authentication method or access grant. */
final class AISA_MCP_Transport {
    const VERSIONS=array('2025-06-18','2025-03-26');
    public static function permission($r) {
        if(!AISA_Agent::settings()['mcp']||!current_user_can('manage_options')||!is_ssl())return false;
        $origin=(string)($r->get_header('Origin')??'');if($origin==='')return true;
        if(strlen($origin)>2048)return new WP_Error('mcp_origin','Origin is not permitted.',array('status'=>403));
        $given=wp_parse_url($origin);$expected=wp_parse_url(rest_url('aisa/v1/mcp'));
        if(!is_array($given)||!isset($given['scheme'],$given['host'])||isset($given['user'])||isset($given['pass'])||isset($given['query'])||isset($given['fragment'])||!in_array($given['path']??'',array('','/'),true))return new WP_Error('mcp_origin','Origin is not permitted.',array('status'=>403));
        $port=fn($v)=>$v['port']??(strtolower($v['scheme']??'')==='https'?443:80);
        if(strtolower($given['scheme'])!==strtolower($expected['scheme'])||strtolower($given['host'])!==strtolower($expected['host'])||$port($given)!==$port($expected))return new WP_Error('mcp_origin','Origin is not permitted.',array('status'=>403));
        return true;
    }
    private static function version_error($r) {
        $version=(string)($r->get_header('MCP-Protocol-Version')??'');
        return $version!==''&&!in_array($version,self::VERSIONS,true)?new WP_Error('mcp_version','Unsupported MCP protocol version.',array('status'=>400)):null;
    }
    public static function accepts_json($header) {
        if($header==='')return true; // Ordinary HTTP default and existing custom POST callers.
        if(strlen($header)>512)return false;$quality=array();
        foreach(explode(',',$header)as$entry){$parts=explode(';',strtolower(trim($entry)));$type=trim(array_shift($parts));if(!in_array($type,array('application/json','application/*','*/*'),true))continue;$q=1.0;
            foreach($parts as$part)if(preg_match('/^\s*q\s*=\s*(.*?)\s*$/',$part,$match))$q=preg_match('/^(?:0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/D',$match[1])?(float)$match[1]:0.0;
            $quality[$type]=isset($quality[$type])?max($quality[$type],$q):$q;
        }
        return ($quality['application/json']??$quality['application/*']??$quality['*/*']??0)>0;
    }
    public static function post($r) {
        if($error=self::version_error($r))return $error;
        $type=(string)($r->get_header('Content-Type')??'');
        if($type!==''&&(strlen($type)>128||strtolower(trim(explode(';',$type)[0]))!=='application/json'))return new WP_Error('mcp_content_type','Use application/json.',array('status'=>415));
        if(!self::accepts_json((string)($r->get_header('Accept')??'')))return new WP_Error('mcp_accept','This endpoint returns application/json responses.',array('status'=>406));
        return null;
    }
    public static function unsupported_method($r) {
        if($error=self::version_error($r))return $error;
        return new WP_REST_Response(array('code'=>'mcp_method','message'=>'Use JSON-RPC POST. Server-initiated SSE and persistent sessions are not offered.'),405,array('Allow'=>'POST','Cache-Control'=>'private, no-store','Content-Type'=>'application/json; charset=utf-8'));
    }
    public static function version($requested) { return in_array($requested,self::VERSIONS,true)?$requested:self::VERSIONS[0]; }
}
