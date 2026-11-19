<?php
defined('ABSPATH') || exit;

/** Fixed presentation presets only; no code, credentials, permissions or remote assets. */
final class AISA_Themes {
    public static function catalog() {
        return array(
            'classic'=>array('Original — default','#6254cc','#bcb5ff'),
            'messenger'=>array('Messenger — WhatsApp-inspired','#176b53','#7edabb'),
            'ocean'=>array('Ocean — calm blue','#12618d','#77d0f4'),
            'slate'=>array('Slate — clean graphite','#475569','#cbd5e1'),
            'forest'=>array('Forest — natural green','#365c2a','#b2d89c'),
            'indigo'=>array('Indigo — focused blue','#4338a8','#b4b8ff'),
            'rose'=>array('Rose — soft pink','#9d2857','#f6afd0'),
            'amber'=>array('Amber — warm gold','#87500f','#f7cf82'),
            'teal'=>array('Teal — fresh aqua','#14645f','#86dfd5'),
            'plum'=>array('Plum — rich violet','#793f88','#dcb2ee')
        );
    }
    public static function valid($id) { return is_string($id) && isset(self::catalog()[$id]); }
    private static function mix($a,$b,$percent) {
        $out='#';for($i=1;$i<7;$i+=2){$out.=sprintf('%02x',(int)round(hexdec(substr($a,$i,2))*$percent/100+hexdec(substr($b,$i,2))*(100-$percent)/100));}return $out;
    }
    public static function palette($id,$appearance='light') {
        $id=self::valid($id)?$id:'classic';$dark=$appearance==='dark';$accent=self::catalog()[$id][$dark?2:1];
        $bg=$dark?self::mix($accent,'#131820',5):self::mix($accent,'#ffffff',4);
        $panel=$dark?self::mix($accent,'#1d2530',5):'#ffffff';
        return array('--ui-accent'=>$accent,'--ui-primary-ink'=>AISA_Widget::ink($accent),'--ui-focus'=>$accent,'--ui-bg'=>$bg,'--ui-panel'=>$panel,
            '--ui-text'=>$dark?'#f3f4f6':'#1f2937','--ui-muted'=>$dark?'#c4cad3':'#4b5563',
            '--ui-line'=>$dark?'#526171':self::mix($accent,'#ffffff',24),
            '--ui-outgoing'=>self::mix($accent,$dark?'#19212d':'#ffffff',$dark?18:12),
            '--ui-incoming'=>$dark?self::mix($accent,'#1d2530',8):self::mix($accent,'#ffffff',5),
            '--ui-hover'=>self::mix($accent,$dark?'#1d2530':'#ffffff',$dark?14:8),
            '--ui-hero'=>self::mix($accent,'#171b26',30),'--ui-hero-text'=>'#ffffff','--ui-hero-muted'=>'#e1e5ed');
    }
    public static function style($id,$appearance) {
        $pairs=array();foreach(self::palette($id,$appearance)as$key=>$value)$pairs[]=$key.':'.$value;return implode(';',$pairs);
    }
    public static function previews() {
        $all=array();foreach(self::catalog()as$id=>$entry)$all[$id]=array('light'=>self::palette($id,'light'),'dark'=>self::palette($id,'dark'));return $all;
    }
}
