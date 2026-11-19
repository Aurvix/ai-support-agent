<?php
defined('ABSPATH') || exit;

/** Bounded presentation settings; never code, arbitrary URLs, permissions or automatic sends. */
final class AISA_Widget {
    public static function positions() {
        // Keep legacy left/right values so saved sites retain their bottom corner.
        return array('top-left'=>'Top left','top-center'=>'Top center','top-right'=>'Top right','center-left'=>'Middle left','center'=>'Center','center-right'=>'Middle right','left'=>'Bottom left','bottom-center'=>'Bottom center','right'=>'Bottom right (default)');
    }
    public static function icons() { return array('none'=>'None','spark'=>'✦','catalog'=>'▦','bag'=>'▱','ticket'=>'▤','chat'=>'◌','link'=>'↗','question'=>'?'); }
    public static function actions() {
        $rows=array(); $names=array('catalog'=>'View Catalog','purchases'=>'My purchases','tickets'=>'My support tickets','human'=>'Talk to a person','payment'=>'Payment options','memory'=>'Remember context','suggest'=>'Suggest an improvement');
        foreach($names as $id=>$label) { $rows[$id]=array('id'=>$id,'enabled'=>in_array($id,array('catalog','purchases','tickets'),true)?1:0,'label'=>$label,'icon'=>'none','order'=>count($rows),'kind'=>'preset','value'=>''); }
        for($i=1;$i<=3;$i++) { $id='custom_'.$i; $rows[$id]=array('id'=>$id,'enabled'=>0,'label'=>'Quick question '.$i,'icon'=>'none','order'=>count($rows),'kind'=>'question','value'=>''); }
        return $rows;
    }
    private static function text($value,$limit) { return is_string($value) && strlen($value)<=$limit && strpos($value,"\0")===false && preg_match('//u',$value) ? sanitize_text_field($value) : null; }
    public static function normalize($raw) {
        $rows=self::actions(); if (!is_array($raw) || count($raw)>10) { return new WP_Error('widget_actions','Use at most ten known quick actions.'); }
        foreach($raw as $id=>$v) {
            if (!isset($rows[$id]) || !is_array($v)) { return new WP_Error('widget_actions','Unknown quick action.'); }
            $label=self::text($v['label']??'',120); $icon=$v['icon']??'none'; $order=filter_var($v['order']??0,FILTER_VALIDATE_INT);
            if ($label===null || trim($label)==='' || !is_string($icon) || !array_key_exists($icon,self::icons()) || $order===false || $order<0 || $order>99) { return new WP_Error('widget_actions','Use a plain label up to 120 bytes, a listed icon and order 0–99.'); }
            $rows[$id]=array_merge($rows[$id],array('enabled'=>empty($v['enabled'])?0:1,'label'=>$label,'icon'=>$icon,'order'=>$order));
            if(str_starts_with($id,'custom_')) {
                $kind=$v['kind']??'question'; $value=self::text($v['value']??'',500);
                if (!in_array($kind,array('question','page'),true) || $value===null || ($rows[$id]['enabled'] && (trim($value)==='' || ($kind==='page' && !ctype_digit($value))))) { return new WP_Error('widget_actions','Custom actions need a question up to 500 bytes or a public page/post/product ID.'); }
                $rows[$id]['kind']=$kind; $rows[$id]['value']=$value;
            }
        }
        return $rows;
    }
    public static function configured($s) { $rows=self::normalize($s['widget_actions']??array()); return is_wp_error($rows)?self::actions():$rows; }
    public static function public_actions($s) {
        $out=array(); foreach(self::configured($s) as $row) {
            if(!$row['enabled'])continue;
            if(in_array($row['id'],array('catalog','purchases'),true) && !function_exists('wc_get_products'))continue;
            if($row['id']==='tickets' && !$s['customer_tickets'])continue;
            if($row['id']==='memory' && !$s['memory'])continue;
            if($row['id']==='suggest' && !$s['learning'])continue;
            if($row['kind']==='page') { $p=get_post((int)$row['value']); if(!$p || !AISA_Public_Sync::permitted($p->ID))continue; $row['url']=get_permalink($p->ID); }
            $out[]=$row;
        }
        usort($out,function($a,$b){return $a['order']<=>$b['order'] ?: strcmp($a['id'],$b['id']);}); return $out;
    }
    public static function ink($hex) {
        $hex=sanitize_hex_color($hex)?:'#635bff';if(strlen($hex)===4)$hex='#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];$rgb=sscanf($hex,'#%02x%02x%02x'); $lum=0;
        foreach($rgb as$i=>$v){$v/=255;$lum+=array(.2126,.7152,.0722)[$i]*($v<=.04045?$v/12.92:pow(($v+.055)/1.055,2.4));}
        return ($lum+.05)/.05 >= 1.05/($lum+.05)?'#000000':'#ffffff';
    }
    public static function save($p,$s) {
        if(array_key_exists('position',$p)) { if(!is_string($p['position']) || !isset(self::positions()[$p['position']]))return new WP_Error('widget_position','Choose a listed widget position.');$s['position']=$p['position']; }
        foreach(array('widget_offset_x','widget_offset_y')as$key)if(array_key_exists($key,$p)) {
            if(!is_string($p[$key]) || !preg_match('/^-?(0|[1-9][0-9]{0,3})$/D',$p[$key]) || abs((int)$p[$key])>2000)return new WP_Error('widget_position','Use a whole-pixel offset from -2000 to 2000.');$s[$key]=(int)$p[$key];
        }
        foreach(array('launcher_label'=>60,'send_label'=>60,'assistant_label'=>60,'input_label'=>120)as$key=>$limit) { if(array_key_exists($key,$p)) { $v=self::text($p[$key],$limit); if($v===null || trim($v)==='')return new WP_Error('widget_label','Use a short plain-text widget label.'); $s[$key]=$v; } }
        if(array_key_exists('header_color',$p)){if(!is_string($p['header_color']) || !sanitize_hex_color($p['header_color']))return new WP_Error('widget_color','Choose a valid header color.');$s['header_color']=$p['header_color'];}
        foreach(array('launcher_icon'=>array_keys(self::icons()),'send_icon'=>array_keys(self::icons()),'widget_size'=>array('compact','comfortable'))as$key=>$values){if(array_key_exists($key,$p)){if(!is_string($p[$key])||!in_array($p[$key],$values,true))return new WP_Error('widget_choice','Choose a listed widget option.');$s[$key]=$p[$key];}}
        if(array_key_exists('avatar_id',$p)) { $id=filter_var($p['avatar_id'],FILTER_VALIDATE_INT);if($id===false||$id<0||($id && !self::avatar($id)))return new WP_Error('widget_avatar','Choose a public local raster image attachment, or 0 for the AI avatar.');$s['avatar_id']=$id; }
        if(array_key_exists('widget_actions',$p)){ $rows=self::normalize($p['widget_actions']);if(is_wp_error($rows))return $rows;$s['widget_actions']=$rows; }
        return $s;
    }
    public static function avatar($id) { if(!in_array(get_post_mime_type($id),array('image/jpeg','image/png','image/gif','image/webp','image/avif'),true))return null;return AISA_Agent::public_media($id); }
    public static function render($s) {
        $rows=self::configured($s); $image=!empty($s['avatar_id'])?self::avatar((int)$s['avatar_id']):null;
        echo '<div class="aisa-two-column"><div class="aisa-card"><h3>Brand & appearance</h3>';
        foreach(array('title'=>'Chat heading','welcome'=>'Welcome message','launcher_label'=>'Launcher label','send_label'=>'Send label','assistant_label'=>'Assistant display label','input_label'=>'Message placeholder')as$key=>$label) { echo '<label class="aisa-field"><span>'.esc_html($label).'</span><input form="aisa-settings" name="'.esc_attr($key).'" value="'.esc_attr($s[$key]).'"></label>'; }
        echo '<div class="aisa-field-grid">';foreach(array('color'=>'Accent color','header_color'=>'Header color')as$key=>$label){echo '<label class="aisa-field"><span>'.esc_html($label).'</span><input form="aisa-settings" type="color" name="'.esc_attr($key).'" value="'.esc_attr($s[$key]).'"></label>';}echo '</div>';
        foreach(array('widget_size'=>array('compact'=>'Compact','comfortable'=>'Comfortable'),'launcher_icon'=>self::icons(),'send_icon'=>self::icons())as$key=>$choices){echo '<label class="aisa-field"><span>'.esc_html(ucwords(str_replace('_',' ',$key))).'</span><select form="aisa-settings" name="'.esc_attr($key).'">';foreach($choices as$value=>$label){echo '<option value="'.esc_attr($value).'" '.selected($s[$key],$value,false).'>'.esc_html($label).'</option>';}echo '</select></label>';}
        echo '<label class="aisa-field"><span>Corner radius (0–32)</span><input form="aisa-settings" type="number" min="0" max="32" name="radius" value="'.(int)$s['radius'].'"></label><label class="aisa-field"><span>Profile image attachment ID (0 = AI avatar)</span><input form="aisa-settings" type="number" min="0" name="avatar_id" value="'.(int)$s['avatar_id'].'"></label><button type="button" class="button" data-aisa-avatar>Choose profile image</button> <a href="'.esc_url(admin_url('upload.php')).'" target="_blank" rel="noopener noreferrer">Media Library</a><p class="aisa-help">Use a public JPEG/PNG/GIF/WebP/AVIF from Media Library. Private parents, credentials, arbitrary remote URLs and uploaded SVG are rejected. The fixed AI identity remains. Foreground automatically uses black or white for contrast; focus stays visible. Live preview reflects unsaved visual choices without sending messages. New image IDs are verified after save.</p></div>';
        $avatar=$image?$image['url']:AISA_URL.'assets/assistant.svg';
        echo '<div class="aisa-preview-stage"><span class="aisa-preview-caption">LIVE PREVIEW · NO MESSAGES SENT</span><div class="aisa-subnav" aria-label="Preview viewport"><button type="button" class="button" data-aisa-preview-viewport="desktop">Desktop</button><button type="button" class="button" data-aisa-preview-viewport="mobile">Mobile</button></div><div class="aisa-widget-preview" style="--aisa-preview-accent:'.esc_attr(sanitize_hex_color($s['color'])?:'#635bff').';--aisa-preview-ink:'.esc_attr(self::ink($s['color'])).';--aisa-preview-header:'.esc_attr(sanitize_hex_color($s['header_color'])?:'#f6f5ff').';--aisa-preview-header-ink:'.esc_attr(self::ink($s['header_color'])).';--aisa-preview-radius:'.(int)$s['radius'].'px" data-aisa-live-preview aria-label="Live widget appearance preview"><div class="aisa-preview-header"><img alt="" src="'.esc_url($avatar).'" width="32" height="32"><strong>'.esc_html($s['title']).'<small>'.esc_html($s['assistant_label']).(preg_match('/\bAI\b/i',$s['assistant_label'])?'':' · AI').' · Website chat</small></strong></div><div class="aisa-preview-messages"><p>'.esc_html($s['welcome']).'</p>';
        foreach(self::public_actions($s)as$row){echo '<span class="aisa-preview-chip">'.esc_html(($row['icon']==='none'?'':self::icons()[$row['icon']].' ').$row['label']).'</span>';}
        echo '</div><div class="aisa-preview-composer"><span data-aisa-preview-placeholder>'.esc_html($s['input_label']).'</span><span class="aisa-preview-send" data-aisa-preview-send>'.esc_html($s['send_label']).'</span></div><small class="aisa-preview-footer">'.esc_html(ucfirst($s['widget_size'])).' · '.esc_html(ucfirst($s['position'])).' · no messages sent</small></div><button type="button" class="aisa-preview-launch" aria-label="Preview support launcher"><span data-aisa-preview-icon>✦</span><span data-aisa-preview-launch-label>'.esc_html($s['launcher_label']).'</span></button><p class="aisa-help">Visual preview only. No provider request, customer reply or automatic save. AI identity stays visible.</p></div></div>';
        echo '<div class="aisa-card" id="aisa-widget-position"><h3>Widget positioning</h3><p>Place the floating chat anywhere around the screen. Move it above a sticky bottom menu with Vertical offset; no theme CSS edits are needed.</p><label class="aisa-field"><span>Screen position</span><select form="aisa-settings" name="position">';foreach(self::positions()as$value=>$label){echo '<option value="'.esc_attr($value).'" '.selected($s['position'],$value,false).'>'.esc_html($label).'</option>';}echo '</select></label><div class="aisa-field-grid">';foreach(array('widget_offset_x'=>'Horizontal offset (px)','widget_offset_y'=>'Vertical offset (px)')as$key=>$label){echo '<label class="aisa-field"><span>'.esc_html($label).'</span><input form="aisa-settings" type="number" step="1" min="-2000" max="2000" name="'.esc_attr($key).'" value="'.(int)$s[$key].'"></label>';}echo '</div><p class="aisa-help">Offsets measure inward from an edge; at center, positive moves right/down and negative moves left/up. Existing bottom-left/right choices are preserved. The launcher and open panel are kept inside the visible viewport; large offsets are limited on small screens. This positions a floating widget, not an embedded page-builder block. Save this view, then refresh your website to see the result.</p></div>';
        echo '<div class="aisa-card"><h3>Quick actions</h3><p>Enable, label and order a fixed safe preset or up to three custom actions. Removed controls stay off until deliberately enabled here. Feature permissions/settings still apply. Custom questions only fill the composer; public-page actions navigate, never send, refund or change an account.</p><div class="aisa-action-editor">';
        foreach($rows as$id=>$row){$name='widget_actions['.$id.']';echo '<fieldset><legend>'.esc_html(str_replace('_',' ',$id)).'</legend><label><input form="aisa-settings" type="checkbox" name="'.esc_attr($name.'[enabled]').'" value="1" '.checked($row['enabled'],1,false).'> Enabled</label><label>Label<input form="aisa-settings" name="'.esc_attr($name.'[label]').'" maxlength="120" value="'.esc_attr($row['label']).'"></label><label>Order<input form="aisa-settings" type="number" min="0" max="99" name="'.esc_attr($name.'[order]').'" value="'.(int)$row['order'].'"></label><label>Icon<select form="aisa-settings" name="'.esc_attr($name.'[icon]').'">';foreach(self::icons()as$icon=>$label){echo '<option value="'.esc_attr($icon).'" '.selected($row['icon'],$icon,false).'>'.esc_html($label).'</option>';}echo '</select></label>';
        if(str_starts_with($id,'custom_')){echo '<label>Action<select form="aisa-settings" name="'.esc_attr($name.'[kind]').'"><option value="question" '.selected($row['kind'],'question',false).'>Fill a question</option><option value="page" '.selected($row['kind'],'page',false).'>Open public content</option></select></label><label>Question (500 bytes) or public content ID<input form="aisa-settings" name="'.esc_attr($name.'[value]').'" value="'.esc_attr($row['value']).'"></label>'; }echo '</fieldset>';}
        echo '</div></div>';
    }
}
