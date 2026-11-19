<?php
defined('ABSPATH') || exit;

/** Administrator-authored public knowledge, never model-weight training or customer auto-learning. */
final class AISA_Training {
    private static function guard() {
        if (!current_user_can('manage_options')) { wp_die('Not allowed.','',array('response'=>403)); }
        check_admin_referer('aisa_admin');
    }
    private static function text($key,$limit) {
        $value=wp_unslash($_POST[$key]??'');
        if (!is_string($value) || strlen($value)>$limit || strpos($value,"\0")!==false || !preg_match('//u',$value)) { wp_die('Use valid UTF-8 text within the stated limit.','',array('response'=>400)); }
        return sanitize_textarea_field($value);
    }
    private static function provenance($id,$kind,$name='') {
        $data=array('kind'=>$kind,'label'=>$name,'actor'=>get_current_user_id(),'created'=>time());
        update_post_meta($id,'aisa_origin',$data);
        return get_post_meta($id,'aisa_origin',true)===$data;
    }
    public static function origin($d) {
        $origin=get_post_meta($d->ID,'aisa_origin',true);
        if (is_array($origin) && in_array($origin['kind']??'',array('teaching','import','conversation'),true)) { return $origin; }
        $source=(int)get_post_meta($d->ID,'aisa_source',true);
        return array('kind'=>$source?'website':'legacy','label'=>$source?'Website source #'.$source:'Existing record; original import/author provenance unavailable');
    }
    /** Display stored provenance only; missing legacy upload details remain explicitly unknown. */
    public static function metadata_label($origin) {
        $kind=$origin['kind']??'legacy';
        $created=is_int($origin['created']??null) && $origin['created']>0 ? gmdate('Y-m-d H:i',$origin['created']).' UTC' : 'Unknown';
        if ($kind==='import') {
            $extension=strtolower(pathinfo(is_string($origin['label']??null)?$origin['label']:'',PATHINFO_EXTENSION));
            $type=$extension==='txt'?'TXT / UTF-8 text':(in_array($extension,array('md','markdown'),true)?'Markdown / UTF-8 text':'Unknown');
            return 'Type: '.$type.' · Uploaded: '.$created;
        }
        if ($kind==='conversation') { return 'Type: Unverified chat-derived draft · Saved: '.$created; }
        if ($kind==='teaching') { return 'Type: Administrator Q/A · Saved: '.$created; }
        if ($kind==='website') { return 'Type: Website source'; }
        return 'Type: Unknown · Original upload date: Unknown';
    }
    public static function teach() {
        self::guard(); $question=self::text('teaching_question',500); $answer=self::text('teaching_answer',12000);
        if (trim($question)==='' || trim($answer)==='') { wp_die('Add a customer question/topic and the public answer or fact to teach.','',array('response'=>400)); }
        $content="Question / topic: ".$question."\nAnswer / public fact: ".$answer;
        $id=wp_insert_post(wp_slash(array('post_type'=>'aisa_knowledge','post_status'=>'private','post_title'=>sanitize_text_field($question),'post_content'=>$content)),true);
        if (!$id || is_wp_error($id)) { wp_die('Teaching entry was not saved. Retry after checking storage.','',array('response'=>503)); }
        if (!self::provenance($id,'teaching','Administrator teaching')) { wp_die('Entry was saved pending review, but its attribution could not be saved. Find it under Existing / unknown origin before retrying.','',array('response'=>503)); }
        $_POST['aisa_view']='training'; AISA_Agent::back();
    }
    public static function edit() {
        self::guard(); $d=get_post(absint($_POST['id']??0)); $hash=wp_unslash($_POST['review_hash']??'');
        if (!$d || $d->post_type!=='aisa_knowledge' || $d->post_status!=='private') { wp_die('Knowledge entry not found.','',array('response'=>404)); }
        if (!is_string($hash) || !hash_equals(AISA_Agent::review_signature($d),$hash)) { wp_die('Entry changed. Reload before editing.','',array('response'=>409)); }
        if ((int)get_post_meta($d->ID,'aisa_source',true)) { wp_die('Edit the original website source, then re-index and review it.','',array('response'=>400)); }
        $title=sanitize_text_field(self::text('knowledge_title',500)); $text=self::text('knowledge_text',262144);
        if (trim($title)==='' || trim($text)==='') { wp_die('Title and public knowledge text are required.','',array('response'=>400)); }
        delete_post_meta($d->ID,'aisa_approved_hash');
        if (get_post_meta($d->ID,'aisa_approved_hash',true)!=='') { wp_die('Could not revoke the old approval; entry was not edited. Reload and retry.','',array('response'=>503)); }
        $result=wp_update_post(wp_slash(array('ID'=>$d->ID,'post_title'=>$title,'post_content'=>$text)),true); $saved=get_post($d->ID);
        if (!$result || is_wp_error($result) || !$saved || $saved->post_title!==$title || $saved->post_content!==$text) { wp_die('Edit could not be verified. The old approval was removed. Reload before retrying.','',array('response'=>503)); }
        $_POST['aisa_view']='training'; AISA_Agent::back();
    }
    public static function upload_batch() {
        self::guard(); $files=$_FILES['knowledge_files']??null;
        if (!is_array($files) || !is_array($files['name']??null) || count($files['name'])<1 || count($files['name'])>10) { wp_die('Choose 1 to 10 TXT or Markdown files, at most 256 KB each and 1 MB total. Check the server upload limit.','',array('response'=>400)); }
        foreach (array('error','tmp_name','size') as $key) { if (!is_array($files[$key]??null) || array_keys($files[$key])!==array_keys($files['name'])) { wp_die('Invalid upload batch.','',array('response'=>400)); } }
        $results=array(); $total=0;
        foreach ($files['name'] as $i=>$name) {
            $label=is_string($name)?sanitize_file_name($name):'Invalid file'; $status='Not imported: invalid file or upload failure';
            $tmp=$files['tmp_name'][$i]; $size=$files['size'][$i];
            if (!is_string($name) || !is_string($tmp) || $files['error'][$i]!==UPLOAD_ERR_OK || !is_numeric($size) || $size<0 || $size>262144 || !is_uploaded_file($tmp) || !in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),array('txt','md'),true)) { $results[]=array('file'=>$label,'status'=>$status); continue; }
            $text=@file_get_contents($tmp,false,null,0,262145);
            if (!is_string($text) || strlen($text)>262144 || strpos($text,"\0")!==false || !preg_match('//u',$text)) { $results[]=array('file'=>$label,'status'=>'Not imported: requires UTF-8 text up to 256 KB'); continue; }
            $total+=strlen($text);
            if ($total>1048576) { $results[]=array('file'=>$label,'status'=>'Not imported: batch exceeds 1 MB'); continue; }
            $text=sanitize_textarea_field($text);
            if (trim($text)==='') { $results[]=array('file'=>$label,'status'=>'Not imported: no usable text'); continue; }
            $id=wp_insert_post(wp_slash(array('post_type'=>'aisa_knowledge','post_status'=>'private','post_title'=>$label,'post_content'=>$text)),true);
            if (!$id || is_wp_error($id)) { $status='Not imported: storage failure'; }
            elseif (!self::provenance($id,'import',$label)) { $status='Saved pending review (#'.$id.'); attribution failed, listed as Existing / unknown origin'; }
            else { $status='Imported pending review (#'.$id.')'; }
            $results[]=array('file'=>$label,'status'=>$status);
        }
        // Per-user short-lived receipt contains filenames/status only, never file contents or paths.
        $token=bin2hex(random_bytes(16)); $key='aisa_import_'.get_current_user_id().'_'.$token;
        set_transient($key,$results,600);
        if (get_transient($key)!==$results) { wp_die('Batch processed, but the result receipt could not be saved. Check the newest knowledge entries before retrying to avoid duplicates.','',array('response'=>503)); }
        wp_safe_redirect(add_query_arg(array('page'=>'aisa','view'=>'training','import_result'=>$token),admin_url('admin.php'))); exit;
    }
    public static function render($review) {
        if (!current_user_can('manage_options')) { return; }
        $token=$_GET['import_result']??'';
        if (is_string($token) && preg_match('/^[a-f0-9]{32}$/',$token)) {
            $results=get_transient('aisa_import_'.get_current_user_id().'_'.$token);
            echo '<div class="aisa-card" role="status"><h3>Import results</h3>';
            if (is_array($results)) { echo '<ul>'; foreach ($results as $result) { echo '<li>'.esc_html($result['file']).': '.esc_html($result['status']).'</li>'; } echo '</ul><p>Successful files are retained even when another fails. Retry only failed files.</p>'; }
            else { echo '<p>Receipt expired. Check the newest entries before retrying an upload.</p>'; }
            echo '</div>';
        }
        echo '<div class="aisa-card"><h3>Teach your assistant</h3><p>Write a customer question and the answer you want it to know. This saves a structured knowledge entry for your review. It does not train model weights, chat with a provider or learn from customers automatically.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aisa_teach"><input type="hidden" name="aisa_view" value="training">'; wp_nonce_field('aisa_admin');
        echo '<label class="aisa-field"><span>Customer question or topic (up to 500 bytes)</span><textarea name="teaching_question" rows="2" required></textarea></label><label class="aisa-field"><span>Your public answer / fact (up to 12,000 bytes)</span><textarea name="teaching_answer" rows="5" required></textarea></label><button class="button button-primary">Save teaching entry for review</button><p class="aisa-help">Only public support facts. Do not enter secrets, customer details or instructions to bypass rules. Review and approve below before customers can use it.</p></form></div>';
        echo '<div class="aisa-two-column"><div class="aisa-card"><h3>Import support files</h3><p>Select up to 10 UTF-8 TXT/Markdown files, 256 KB each and 1 MB total. Imported text stays pending until reviewed. Original files are not stored.</p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aisa_upload_batch">'; wp_nonce_field('aisa_admin');
        echo '<label class="aisa-upload">Support files<input required multiple type="file" name="knowledge_files[]" accept=".txt,.md"></label><button class="button">Import files for review</button></form></div><div class="aisa-card"><h3>Index public website content</h3><p>Collects posts, pages and visible products in batches of 50. Indexing preserves provenance. The public-reference policy above can automatically authorize existing and new ordinary website sources; explicit exclusions stay held. Files and teaching are never authorized by this indexing job.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aisa_index"><input type="hidden" name="aisa_view" value="training">'; wp_nonce_field('aisa_admin'); echo '<button class="button">Index next batch</button></form></div></div>';
        echo '<div class="aisa-card"><h3>Customer suggestions awaiting review</h3><p>These suggestions are never approved automatically.</p>';
        $drafts=get_posts(array('post_type'=>'aisa_draft','post_status'=>'pending','posts_per_page'=>20)); foreach ($drafts as $draft) { $review($draft); } if (!$drafts) { echo '<p>No pending suggestions.</p>'; } echo '</div>';
        $filter=$_GET['origin']??'all'; if (!is_string($filter) || !in_array($filter,array('all','import','teaching','website','legacy'),true)) { $filter='all'; }
        $search=is_string($_GET['knowledge_search']??null)?sanitize_text_field(wp_unslash($_GET['knowledge_search'])):'';
        $page=max(1,absint($_GET['knowledge_page']??1));
        // Match the exact serialized kind member in private provenance; legacy records remain in All sources.
        $args=array('post_type'=>'aisa_knowledge','post_status'=>'private','posts_per_page'=>20,'paged'=>$page,'orderby'=>'ID','order'=>'DESC','s'=>$search);
        if ($filter==='website') { $args['meta_query']=array(array('key'=>'aisa_source','compare'=>'EXISTS')); }
        elseif ($filter==='legacy') { $args['meta_query']=array(array('key'=>'aisa_source','compare'=>'NOT EXISTS'),array('key'=>'aisa_origin','compare'=>'NOT EXISTS')); }
        elseif (in_array($filter,array('import','teaching'),true)) { $args['meta_query']=array(array('key'=>'aisa_origin','value'=>'"kind";s:'.strlen($filter).':"'.$filter.'"','compare'=>'LIKE')); }
        $query=new WP_Query($args);
        echo '<div class="aisa-card aisa-source-list"><h3>Knowledge library</h3><p>Newest first. Eligible public website sources follow your site-wide public-reference policy. Imports, teaching and suggestions require manual review. Excluded or stale records cannot answer customers; provenance is retained.</p><form method="get" action="'.esc_url(admin_url('admin.php')).'"><input type="hidden" name="page" value="aisa"><input type="hidden" name="view" value="training"><label>Search <input name="knowledge_search" value="'.esc_attr($search).'"></label> <label>Source <select name="origin">';
        foreach (array('all'=>'All sources','import'=>'Imported files','teaching'=>'Admin teaching','website'=>'Website sources','legacy'=>'Existing / unknown origin') as $kind=>$label) { echo '<option value="'.esc_attr($kind).'" '.selected($filter,$kind,false).'>'.esc_html($label).'</option>'; } echo '</select></label> <button class="button">Filter</button></form>';
        foreach ($query->posts as $d) {
            $origin=self::origin($d); $approved=AISA_Agent::knowledge_approved($d); $stamp=get_post_meta($d->ID,'aisa_approved_hash',true);$website=(int)get_post_meta($d->ID,'aisa_source',true);$public_status=$website ? (!AISA_Public_Sync::permitted($website,true)?'Excluded by public-access / scope rules':(get_post_meta($website,AISA_Public_Sync::HOLD,true)?'Excluded by administrator':($approved?'Public website reference · current':(AISA_Public_Sync::all_public()?'Waiting for current sync':'Public reference policy is off or needs configuration')))) : '';
            echo '<div class="aisa-knowledge-record"><p><strong>#'.esc_html($d->ID).' · '.esc_html(ucfirst($origin['kind'])).'</strong> · '.esc_html($public_status!==''?$public_status:($approved?(AISA_Agent::knowledge_mode($d)==='auto'?'Public auto-sync · current':'Approved'):($stamp!==''?'Changed / stale approval':'Pending review / not approved'))).'</p><p class="aisa-help">'.esc_html($origin['label']).(!empty($origin['actor'])?' · Administrator #'.esc_html($origin['actor']):'').'</p>';
            echo '<p class="aisa-help aisa-document-metadata">'.esc_html(self::metadata_label($origin)).'</p>';
            $review($d);
            if (!(int)get_post_meta($d->ID,'aisa_source',true)) {
                echo '<details><summary>Edit entry (requires re-approval)</summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aisa_edit_knowledge"><input type="hidden" name="id" value="'.esc_attr($d->ID).'"><input type="hidden" name="review_hash" value="'.esc_attr(AISA_Agent::review_signature($d)).'">'; wp_nonce_field('aisa_admin');
                echo '<label class="aisa-field"><span>Title (up to 500 bytes)</span><input name="knowledge_title" value="'.esc_attr($d->post_title).'" required></label><label class="aisa-field"><span>Public knowledge (up to 256 KB)</span><textarea name="knowledge_text" rows="6" required>'.esc_textarea($d->post_content).'</textarea></label><button class="button">Save edit and remove approval</button></form></details>';
            } else { echo '<p class="aisa-help">Edit this reference on its original website page. Eligible public changes follow the site-wide policy; exclusions remain in force.</p>'; }
            echo '</div>';
        }
        if (!$query->posts) { echo '<p>No entries match this view. Try All sources or clear the search.</p>'; }
        echo '<nav class="aisa-pagination" aria-label="Knowledge pages">';
        foreach (array($page-1=>'Previous',$page+1=>'Next') as $number=>$label) { if ($number<1 || $number>$query->max_num_pages) { continue; } echo '<a class="button" href="'.esc_url(add_query_arg(array('page'=>'aisa','view'=>'training','origin'=>$filter,'knowledge_search'=>$search,'knowledge_page'=>$number),admin_url('admin.php'))).'">'.esc_html($label).'</a>'; }
        echo '<span>Page '.esc_html($page).' of '.esc_html(max(1,$query->max_num_pages)).'</span></nav></div>';
    }
}
