<?php
defined('ABSPATH') || exit;

/** Configuration UI only: rendering this page never contacts providers or customers. */
final class AISA_Admin {
    public static function inbox_tab() {
        return ($_GET['inbox_tab']??'conversations')==='handoff'?'handoff':'conversations';
    }
    public static function inbox_url($tab='conversations') {
        return add_query_arg(array('page'=>'aisa','view'=>'inbox','inbox_tab'=>$tab==='handoff'?'handoff':'conversations'),admin_url('admin.php'));
    }
    private static function render_inbox() {
        $tab=self::inbox_tab();
        echo '<nav class="aisa-inbox-tabs" aria-label="Inbox categories">';
        foreach(array('conversations'=>'Conversations','handoff'=>'Handoff requests')as$id=>$label){echo '<a href="'.esc_url(self::inbox_url($id)).'" '.($tab===$id?'aria-current="page"':'').'>'.esc_html($label).'</a>';}
        echo '</nav>';
        if($tab==='conversations'){AISA_Conversations::render();return;}
        echo '<section id="aisa-handoff-requests" class="aisa-section"><div class="aisa-section-heading"><div><h2>Handoff requests</h2><p>Review local tickets and account-linked requests. Website conversations and live replies stay in Conversations.</p></div></div>';
        AISA_Inbox::render();AISA_Handoff::render();echo '</section>';
    }
    private static function badge($text,$tone='muted') {
        echo '<span class="aisa-badge aisa-badge-'.esc_attr($tone).'">'.esc_html($text).'</span>';
    }
    private static function heading($number,$title,$description) {
        echo '<div class="aisa-section-heading"><span class="aisa-step">'.esc_html($number).'</span><div><h2>'.esc_html($title).'</h2><p>'.esc_html($description).'</p></div></div>';
    }
    // Shape observations only. Never resolve DNS, contact a URL or echo its secret value.
    public static function url_observation($value) {
        if (!is_string($value) || $value==='') { return 'missing'; }
        $url=wp_parse_url($value);
        if (!is_array($url) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || filter_var($value,FILTER_VALIDATE_URL)===false) { return 'invalid'; }
        if (strtolower($url['scheme']??'')!=='https') { return 'https_required'; }
        $host=strtolower(trim($url['host'],'[]')); $host=rtrim($host,'.');
        if ($host==='localhost' || str_ends_with($host,'.localhost') || str_ends_with($host,'.local') || str_ends_with($host,'.internal') || (filter_var($host,FILTER_VALIDATE_IP)!==false && filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)) { return 'private_address'; }
        return 'shape_set';
    }
    private static function url_label($state) {
        return array('missing'=>'HTTPS endpoint missing','invalid'=>'URL needs review','https_required'=>'HTTPS required','private_address'=>'Local/private address warning','shape_set'=>'URL shape set · untested')[$state]??'URL needs review';
    }
    private static function configuration($s,$credentials) {
        $custom=in_array($s['provider'],array('local','compatible'),true);
        $resolved=AISA_Provider::resolve($s); $shape=self::url_observation($resolved['endpoint']);
        if (!in_array($s['provider'],array('openai','gemini','nvidia','groq','local','compatible'),true)) { $provider='Provider selection invalid'; }
        elseif ($resolved['model']==='') { $provider='Needs setup'; }
        elseif ($custom && $shape!=='shape_set') { $provider=self::url_label($shape); }
        elseif ($s['secret']!=='' && !AISA_Provider::matches($s)) { $provider='Saved key belongs to another connection'; }
        elseif ($s['secret']!=='' && !$credentials['model']) { $provider='Credential missing or unreadable'; }
        elseif (!$custom && !$credentials['model']) { $provider='Credential missing or unreadable'; }
        else { $provider=$custom && !$credentials['model']?'No API key stored · gateway auth unverified':'Configured · untested'; }
        $observed=AISA_Provider::observation($s);
        if ($provider==='Configured · untested' && $observed) { $provider=$observed['category']==='ok'?'Configured · last response succeeded':($observed['category']==='testing'?'Configured · test incomplete':'Configured · last response failed'); }
        $telegram=!$s['telegram_secret'] && !$s['telegram_chat']?'Not configured':(!$credentials['telegram'] || !$s['telegram_chat']?'Incomplete':(!empty($credentials['telegram_format'])?'Configured · untested':'Token format needs review'));
        $relay=$s['webhook_secret']===''?'Not configured':(empty($credentials['relay_readable'])?'Saved relay unreadable':self::url_label($credentials['relay_shape']??'invalid'));
        return array(
            'provider'=>array('Model connection',$provider,'Choose a provider; automatic uses its bundled model preset. Manual override is optional. Compatible/local adapters permit an omitted API key; gateway authentication must be verified separately. A saved unreadable key is never treated as configured.','providers'),
            'endpoint'=>array('Custom model URL',$custom?self::url_label($shape):'Provider-managed URL · untested','Custom providers need an absolute public HTTPS URL without embedded username/password or a fragment. This check does not resolve DNS or verify routing, TLS, reachability or authentication. WordPress safe HTTP still decides at request time.','providers'),
            'email'=>array('Notification email',$s['notify_email']===''?'Not configured':(filter_var($s['notify_email'],FILTER_VALIDATE_EMAIL)?'Address shape set · untested':'Address needs review'),'Set a complete admin email address. Its format does not prove that a mailbox exists or that mail can be delivered.','notifications'),
            'telegram'=>array('Telegram',$telegram,'Both a readable token in the expected format and a chat ID are needed. No token is shown or tested here; format alone does not prove access or delivery.','notifications'),
            'relay'=>array('HTTPS relay',$relay,'A saved unreadable relay must be re-entered through its existing field. Use an absolute public HTTPS destination. This panel never reveals or contacts the stored URL.','notifications'),
            'handoff'=>array('Human contact',!$s['support_email'] && !$s['handoff_url']?'Not configured':(($s['support_email'] && !filter_var($s['support_email'],FILTER_VALIDATE_EMAIL)) || ($s['handoff_url'] && self::url_observation($s['handoff_url'])!=='shape_set')?'Contact needs review':'Contact set · untested'),'Provide a usable support email or public HTTPS handoff link. Contact configuration does not enable replies, refunds or automatic reassignment.','notifications'),
            'mcp'=>array('Read-only MCP',!$s['mcp']?'Disabled':(!is_ssl()?'Blocked on HTTP':'Enabled · untested'),'Use the existing authorized HTTPS access path and verify an actual client separately. This page creates no credentials and grants no access.','mcp'),
            'knowledge'=>array('Support knowledge','Approval checked per record','Review sources in Knowledge approvals. Stored counts are not approval or freshness counts; retrieval rechecks approval and the current source every turn.','training')
        );
    }
    public static function render($s,$credentials,$field,$review) {
        if (!current_user_can('manage_options')) { return; }
        wp_enqueue_style('aisa-admin',AISA_URL.'assets/admin.css',array(),AISA_VERSION);
        $view=AISA_Agent::view();
        wp_enqueue_script('aisa-admin',AISA_URL.'assets/admin.js',array(),AISA_VERSION,true);
        wp_enqueue_script('aisa-experience',AISA_URL.'assets/admin-experience.js',array('aisa-admin'),AISA_VERSION,true);
        $woocommerce=function_exists('wc_get_orders') && function_exists('wc_get_customer_available_downloads');
        $checks=self::configuration($s,$credentials); $provider=$checks['provider'][1];
        $modelCredential=$credentials['model']?'Stored and decryptable · untested':($s['secret']==='' && in_array($s['provider'],array('local','compatible'),true)?'Not stored · optional for this adapter':'Missing or unreadable');
        $handoff=$s['support_email']!=='' || $s['handoff_url']!=='';
        $sources=(int)wp_count_posts('aisa_knowledge')->private;
        $suggestions=(int)wp_count_posts('aisa_draft')->pending;
        $providerNames=array('openai'=>'OpenAI','gemini'=>'Gemini','nvidia'=>'NVIDIA','groq'=>'Groq','compatible'=>'Compatible API','local'=>'Local gateway');
        $sections=array('overview'=>'Overview','inbox'=>'Chat Inbox','assistant'=>'Admin AI assistant','providers'=>'Providers','widget'=>'Widget','training'=>'Training','woocommerce'=>'WooCommerce','notifications'=>'Notifications','mcp'=>'MCP','settings'=>'Settings','diagnostics'=>'Diagnostics');
        ?>
        <!-- Core moves non-inline notices after this anchor; keep them outside support cards. -->
        <div class="wrap aisa-notice-area" role="region" aria-label="WordPress system notices"><hr class="wp-header-end"></div>
        <div class="wrap aisa-admin<?php echo $view==='inbox'?' aisa-inbox-workspace':''; ?>" data-theme="<?php echo esc_attr($s['admin_theme']); ?>" data-ui-theme="<?php echo esc_attr($s['ui_theme']); ?>" style="<?php echo esc_attr(AISA_Themes::style($s['ui_theme'],$s['admin_theme'])); ?>">
            <header class="aisa-topbar"><div class="aisa-brand"><span class="aisa-brand-mark" aria-hidden="true">✦</span><span>AI Support Agent<small>YOUR SUPPORT CONTROL CENTER</small></span></div><span class="aisa-version">v<?php echo esc_html(AISA_VERSION); ?></span></header>
            <?php if (isset($_GET['updated'])) { ?><div class="notice notice-success inline"><p>Update recorded. Connection and delivery status remain untested.</p></div><?php } ?>
            <div class="aisa-workspace">
                <aside class="aisa-sidebar"><p class="aisa-eyebrow">WORKSPACE</p><nav aria-label="Support settings sections">
                    <?php $i=0; foreach ($sections as $id=>$label) { $i++; ?><a href="<?php echo esc_url(add_query_arg(array('page'=>'aisa','view'=>$id),admin_url('admin.php'))); ?>" <?php echo $view===$id?'aria-current="page"':''; ?>><span aria-hidden="true"><?php echo esc_html(str_pad((string)$i,2,'0',STR_PAD_LEFT)); ?></span><?php echo esc_html($label); ?></a><?php } ?>
                </nav><div class="aisa-sidebar-note"><strong>Keep a person in the loop.</strong><p>Approve your knowledge and verify the experience before enabling customer chat.</p></div></aside>
                <main class="aisa-main">
                    <form id="aisa-settings" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" hidden><input type="hidden" name="action" value="aisa_save"><input type="hidden" name="aisa_view" value="<?php echo esc_attr($view); ?>"><input type="hidden" name="settings_revision" value="<?php echo esc_attr(AISA_Agent::revision()); ?>"><?php wp_nonce_field('aisa_admin'); ?></form>
                    <?php if ($view==='overview') { ?>
<section id="aisa-overview" class="aisa-section">
                        <div class="aisa-hero"><p class="aisa-eyebrow">OVERVIEW</p><h1>Your support workspace.</h1><p>Connect your model, keep public facts current and give customers a clear path to your team.</p><div class="aisa-hero-tags"><?php self::badge($s['enabled']?'Chat enabled · untested':'Chat disabled',$s['enabled']?'warning':'muted'); self::badge('Site-specific checks in Diagnostics','muted'); ?></div></div>
                        <div class="aisa-metrics"><div><span>PROVIDER</span><strong><?php echo esc_html($providerNames[$s['provider']]??'Unknown provider'); ?></strong><small><?php echo esc_html($provider); ?></small></div><div><span>KNOWLEDGE SOURCES</span><strong><?php echo esc_html($sources); ?></strong><small>Stored sources · approval checked per record</small></div><div><span>LEARNING QUEUE</span><strong><?php echo esc_html($suggestions); ?></strong><small>Suggestions waiting for your review</small></div></div>
                        <div class="aisa-card aisa-launch-card"><h2>Customer chat</h2><p>Control whether the website widget is available. Configure appearance and destinations before turning it on.</p><div class="aisa-enable-control"><?php $field('enabled','Enable customer chat','checkbox'); ?><strong><?php echo $s['enabled']?'Website chat is enabled':'Website chat is off'; ?></strong></div><p class="aisa-help">Customer messages and relevant public references can be sent to your selected model when chat is enabled. Saving appearance alone never enables chat.</p></div><div class="aisa-card"><h2>First-use checklist</h2><ol class="aisa-checklist"><li><a href="<?php echo esc_url(add_query_arg(array('page'=>'aisa','view'=>'providers'),admin_url('admin.php'))); ?>">Choose your provider and API key</a><span>Saved settings do not prove a successful connection.</span></li><li><a href="<?php echo esc_url(add_query_arg(array('page'=>'aisa','view'=>'training'),admin_url('admin.php'))); ?>">Review and approve your support sources</a><span>Imports and teaching require review; ordinary website references follow your public-source policy.</span></li><li><a href="<?php echo esc_url(add_query_arg(array('page'=>'aisa','view'=>'notifications'),admin_url('admin.php'))); ?>">Add a human support destination</a><span>Give customers a way to continue with your team.</span></li><li><a href="<?php echo esc_url(add_query_arg(array('page'=>'aisa','view'=>'diagnostics'),admin_url('admin.php'))); ?>">Complete staging verification</a><span>Test permissions, downloads, failures and theme behavior.</span></li></ol><div class="aisa-divider"></div><p class="aisa-help">Off by default. Enabling this can send customer questions and approved public facts to the selected model provider. Review your privacy notice and provider terms first.</p></div>
                    </section>
<?php } ?>
                    <?php if ($view==='providers') { ?>
<section id="aisa-providers" class="aisa-section">
                        <?php self::heading('02','Providers','Your choice of model. Server-side credentials.'); ?>
                        <div class="aisa-card"><div class="aisa-card-top"><h3>Model connection</h3><?php self::badge($provider,'warning'); ?></div>
                            <div class="aisa-field-grid"><label class="aisa-field"><span>Provider</span><select form="aisa-settings" name="provider"><?php foreach (array('openai'=>'OpenAI','gemini'=>'Google Gemini','nvidia'=>'NVIDIA','groq'=>'Groq','compatible'=>'OpenAI-compatible API','local'=>'Local / self-hosted gateway') as $key=>$label) { ?><option value="<?php echo esc_attr($key); ?>" <?php selected($s['provider'],$key); ?>><?php echo esc_html($label); ?></option><?php } ?></select></label><?php $field('secret','Model API key','password'); ?></div>
                            <?php $resolved=AISA_Provider::resolve($s); $modelList=AISA_Provider::models($s['provider']); $catalog=AISA_Provider::catalog(); $choice=$s['model_mode']==='auto'?'__auto__':($s['model_mode']==='preset' && isset($modelList[$s['model']])?$s['model']:'__manual__'); ?>
                            <div id="aisa-provider-config" data-presets="<?php echo esc_attr(wp_json_encode(AISA_Provider::ui_catalog())); ?>">
                            <label class="aisa-field"><span>Model</span><select form="aisa-settings" name="model_choice" aria-describedby="aisa-provider-summary">
                            <?php if (isset($catalog[$s['provider']])) { ?><option value="__auto__" <?php selected($choice,'__auto__'); ?>><?php echo esc_html('Keep current automatic default — '.$catalog[$s['provider']]['model']); ?></option><?php } ?>
                            <?php foreach ($modelList as $id=>$entry) { ?><option value="<?php echo esc_attr($id); ?>" <?php selected($choice,$id); ?>><?php echo esc_html($entry['name'].' — '.$id); ?></option><?php } ?>
                            <option value="__manual__" <?php selected($choice,'__manual__'); ?>>Manual override — enter an exact model ID</option></select></label>
                            <p class="aisa-help" id="aisa-provider-summary" role="status"><?php echo esc_html('Selected model: '.($resolved['model']?:'Enter a manual model ID').'. '.AISA_Provider::availability($s['provider'],$resolved['model'])); ?></p>
                            <details <?php echo $choice==='__manual__' || in_array($s['provider'],array('local','compatible'),true)?'open':''; ?>><summary>Advanced connection settings</summary>
                            <div id="aisa-manual-model" <?php echo $choice==='__manual__'?'':'hidden'; ?>><?php $field('model','Manual model ID'); ?><p class="aisa-help">Exact provider model ID, 1–200 characters. Existing manual IDs are retained; choosing another provider does not transfer its key.</p></div>
                            <div id="aisa-custom-endpoint" <?php echo in_array($s['provider'],array('local','compatible'),true)?'':'hidden'; ?>><?php $field('endpoint','Custom chat completions URL','url'); ?><p class="aisa-help">The gateway supplies its own model catalog. No universal local model ID is assumed, and this panel never queries an untrusted gateway.</p></div></details>
                            <p class="aisa-help">Names and IDs reviewed <?php echo esc_html(AISA_Provider::REVIEWED); ?>. This is a documented model list, not live account discovery. Account permission, quota and hosted availability remain unverified until a request succeeds. Deprecated/enterprise restrictions are shown for the selected model. Existing automatic defaults stay unchanged on upgrade.</p>
                            <p class="aisa-help">A blank API key keeps its encrypted value bound to the original provider and URL. Selecting a model does not save, test, clear or replace the key. Save this view deliberately.</p></div>
                            <p class="aisa-help">Official provider URLs are managed automatically. Custom/local services need a public HTTPS gateway; direct localhost/LAN access is blocked. Opening or saving settings sends no provider request. Incomplete, refused, tool-call and malformed answers fail safely. Model calls are never retried automatically.</p>
                            <div class="aisa-divider"></div><h3>Saved connection check</h3>
                            <p class="aisa-help">One fixed synthetic greeting only, up to 128 output tokens and 20 seconds. No knowledge, administrator instructions, customer messages or purchase data are included. Uses the saved model and its fixed official provider URL; may consume provider quota. Save model changes first. One test per minute, subject to the site request limit. No automatic retries.</p>
                            <form class="aisa-probe-controls" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="aisa_provider_probe"><input type="hidden" name="settings_revision" value="<?php echo esc_attr(AISA_Agent::revision()); ?>"><?php wp_nonce_field('aisa_admin'); ?>
                            <label class="aisa-field"><span>Synthetic test</span><select name="probe_variant"><option value="standard">Standard saved-model options</option><option value="compatibility">Minimal compatibility — omit optional reasoning</option></select></label>
                            <button class="button" data-aisa-probe <?php disabled(!isset($catalog[$s['provider']]) || $resolved['model']==='' || !$credentials['model'] || !AISA_Provider::matches($s)); ?>>Check saved model</button></form>
                            <?php $observation=AISA_Provider::observation($s); if ($observation) { ?><div class="aisa-model-observation" role="status"><strong><?php echo esc_html($observation['category']==='ok'?'Last response succeeded':($observation['category']==='testing'?'Test result pending':'Last response needs attention')); ?></strong><p><?php echo esc_html(gmdate('Y-m-d H:i:s',$observation['at']).' UTC · '.($observation['synthetic']?'Synthetic test':'Model request').($observation['http']?' · HTTP '.$observation['http']:' · No HTTP result').($observation['api']?' · '.$observation['api']:'')); ?></p><p><?php echo esc_html(AISA_Provider::guidance($observation['category'])); ?></p><small>Current connection only · expires after one hour. Raw responses, prompts, headers and keys are never displayed.</small></div><?php } else { ?><p class="aisa-help">No recent result for this saved connection. A configured key or a listed model does not prove access.</p><?php } ?>
                            <div class="aisa-divider"></div><h3>Usage boundaries</h3><div class="aisa-field-grid"><?php $field('daily_limit','Model requests per site / day','number'); $field('hourly_limit','Requests per visitor / hour','number'); ?></div><p class="aisa-help">Requests and output tokens are bounded. This is not a currency budget; set spending limits with your provider too. Free tiers and quotas may change.</p>
                        </div>
                    </section>
<?php } ?>
                    <?php if ($view==='widget') { ?>
<section id="aisa-widget" class="aisa-section">
                        <?php self::heading('03','Widget','Small details that make support feel like your site.'); ?>
                        <div class="aisa-card"><h3>Bounded public context memory</h3><?php $field('memory','Allow visitors to opt in to context memory','checkbox'); ?><p class="aisa-help">Off by default, with a separate visitor opt-in in each tab. Stores approved public source/product IDs and approval fingerprints only, never questions, replies, credentials, orders or download links. Histories are separate per site, account, login session and widget session. Maximum 4 turns, 15 minutes absolute expiry, 8 KB state and 2,048 byte-per-token budget units for recalled facts (not an exact provider tokenizer). Each turn rechecks approval and source freshness. The optional Remember context action can reset its own context; Clear Chat stays absent. Disabling memory invalidates all prior scopes. This supports public-topic follow-ups, not verbatim transcript recall.</p></div>
                        <?php AISA_Widget::render($s); AISA_Experience::render_widget($s); ?>
                    </section>
<?php } ?>
                    <?php if ($view==='training') { ?>
<section id="aisa-training" class="aisa-section">
                        <?php self::heading('04','Training','Teach public support facts. Review what customers can use.'); ?>
                        <div class="aisa-card"><h3>Response instructions</h3><?php $field('instructions','Administrator instructions','textarea'); $field('learning','Allow customer suggestions for review','checkbox'); ?><p class="aisa-help">Instructions become active when you save this view. Imports, teaching and customer suggestions need separate approval; eligible website references follow the public policy below. Replies should match the customer language; translated retrieval and model answers still need verification. Do not enter secrets or private customer information.</p></div>
                        <?php AISA_Experience::render_instructions($s); AISA_Public_Sync::render($s); AISA_Training::render($review); ?>
                    </section>
<?php } ?>
                    <?php if ($view==='woocommerce') { ?>
<section id="aisa-woocommerce" class="aisa-section">
                        <?php self::heading('05','WooCommerce','Private purchases stay behind the customer account.'); ?>
                        <?php if ($woocommerce && get_option('woocommerce_downloads_require_login','no')!=='yes') { ?><div class="aisa-callout">Download login requirement is off in WooCommerce. Its native file links can act as bearer links even though this widget only reveals them to the owning account. Enable Downloads require login in WooCommerce download settings if file access must also require the signed-in owner. This plugin does not change that store setting.</div><?php } ?>
                        <div class="aisa-card"><div class="aisa-card-top"><h3>Account access & downloads</h3><?php self::badge($woocommerce?'Detected · untested':'Not detected',$woocommerce?'warning':'muted'); ?></div><p><?php echo $woocommerce?'WooCommerce functions are available. That does not verify your store’s ownership or download behavior.':'WooCommerce is optional. Product, order and download features need WooCommerce; this panel does not install it.'; ?></p><ul class="aisa-feature-list"><li>Customers sign in to see their own purchases.</li><li>Download links come from WooCommerce’s available-download permissions.</li><li>Guest purchases need human assistance or account linking.</li><li>Payment claims in chat cannot mark an order paid, refund it or delete data.</li></ul><div class="aisa-callout">Before public use, test two separate accounts plus pending, paid, refunded, expired and revoked downloads. No order details are sent to the model.</div><div class="aisa-divider"></div><h3>Public payment guidance</h3><?php $field('payment_text','Public payment instructions','textarea'); $field('qr_url','Public payment QR image URL','url'); ?><p class="aisa-help">These values appear in customer chat. Never include private payment credentials. QR images and chat claims are not payment confirmation. Public product references follow your Training policy; current price, stock and access are rechecked.</p></div>
                    </section>
<?php } ?>
                    <?php if ($view==='notifications') { ?>
<section id="aisa-notifications" class="aisa-section">
                        <?php self::heading('06','Notifications','Configure contact destinations and event notifications.'); ?>
                        <div class="aisa-card"><div class="aisa-card-top"><h3>Human support</h3><?php self::badge($handoff?'Contact set · untested':'Contact missing',$handoff?'muted':'warning'); ?></div><div class="aisa-field-grid"><?php $field('support_email','Customer support email','email'); $field('handoff_url','Human support / WhatsApp URL','url'); ?></div><p class="aisa-help">The widget offers these contact links and requests an admin notification. It does not submit a local review ticket or guarantee a reply. WhatsApp uses your link or a separately configured relay.</p></div>
                        <p class="aisa-help" id="aisa-inbox"><span id="aisa-account-tickets"></span>Review tickets in <a href="<?php echo esc_url(self::inbox_url('handoff')); ?>">Chat Inbox → Handoff requests</a>.</p>
                        <div class="aisa-card"><h3>Admin event notifications</h3><div class="aisa-channel-status"><div><strong>Email</strong><?php self::badge($checks['email'][1]); ?></div><div><strong>Telegram</strong><?php self::badge($checks['telegram'][1]); ?></div><div><strong>HTTPS relay</strong><?php self::badge($checks['relay'][1]); ?></div></div><?php AISA_Experience::render_notifications($field); ?><p class="aisa-help">Events: paid purchase and human support request. Payloads contain event type and site name; purchase events include order ID. Message text and customer contact details are omitted. Saving sends no test notification.</p><div class="aisa-callout">Delivery is synchronous and has no retry queue. Ambiguous failures are not automatically resent because destinations may duplicate alerts. Verify your gateway’s payment-complete hook and each destination with disposable test data before relying on alerts.</div></div>
                    </section>
<?php } ?>
                    <?php if ($view==='mcp') { ?>
<section id="aisa-mcp" class="aisa-section">
                        <?php self::heading('07','MCP','A narrow, read-only connection to approved public facts.'); ?>
                        <div class="aisa-card"><div class="aisa-card-top"><h3>Inbound MCP server</h3><?php self::badge(!$s['mcp']?'Disabled':(!is_ssl()?'Blocked on HTTP':'Enabled · untested'),$s['mcp']?'warning':'muted'); ?></div><?php $field('mcp','Enable authenticated read-only MCP','checkbox'); ?><div class="aisa-endpoint"><span>ENDPOINT</span><code><?php echo esc_html(rest_url('aisa/v1/mcp')); ?></code></div><p>Available tools: <code>search_catalog</code> and <code>search_knowledge</code>. Both require approved content. Private orders, downloads, refunds and arbitrary remote actions are not exposed.</p><p class="aisa-help">Connect over HTTPS using a dedicated WordPress administrator Application Password. Create/manage it separately; this plugin does not generate credentials. Stateless JSON-response HTTP profile: 2025-06-18 and 2025-03-26. Supports initialize, notifications, ping, tools/list and tools/call. GET/SSE and session deletion return 405; no persistent sessions are issued. Origin, media and protocol headers are checked. Actual Codex connection and Application Password authentication remain unverified; saving settings does not create credentials or connect a client. Outbound MCP connections are not implemented.</p></div>
                    </section>
<?php } ?>
                    <?php if ($view==='inbox') { self::render_inbox(); } ?>
<?php if ($view==='assistant') { AISA_Admin_Assistant::render(); } ?>
<?php if ($view==='settings') { ?>
<section id="aisa-plugin-settings" class="aisa-section"><div class="aisa-section-heading"><div><h2>Settings</h2><p>Plugin appearance and support-chat configuration.</p></div></div><div class="aisa-card"><h3>Plugin theme</h3><label class="aisa-field"><span>Interface theme</span><select name="ui_theme" aria-label="Interface theme" form="aisa-settings"><?php foreach(AISA_Themes::catalog()as$theme_id=>$theme_entry){ ?><option value="<?php echo esc_attr($theme_id); ?>" <?php selected($s['ui_theme'],$theme_id); ?>><?php echo esc_html($theme_entry[0]); ?></option><?php } ?></select></label><label class="aisa-field"><span>Appearance</span><select name="admin_theme" aria-label="Appearance" form="aisa-settings"><option value="light" <?php selected($s['admin_theme'],'light'); ?>>Light (default)</option><option value="dark" <?php selected($s['admin_theme'],'dark'); ?>>Dark</option></select></label><p class="aisa-help">Applies to the whole plugin and customer chat. Original uses your saved widget branding; other themes provide a matching palette without replacing those saved choices. This changes appearance only, not channel connections or permissions.</p><div class="aisa-theme-preview" data-aisa-theme-preview data-palettes="<?php echo esc_attr(wp_json_encode(AISA_Themes::previews())); ?>" data-ui-theme="<?php echo esc_attr($s['ui_theme']); ?>" data-theme="<?php echo esc_attr($s['admin_theme']); ?>"><strong>Theme preview</strong><span class="aisa-preview-incoming">How can we help?</span><span class="aisa-preview-outgoing">I have a product question.</span><small>Preview only. Save this view to apply.</small></div></div><div class="aisa-card"><h3>Customer handoff requests</h3><input type="hidden" name="customer_tickets_present" value="1" form="aisa-settings"><?php $field('customer_tickets','Enable account-linked structured handoff requests','checkbox'); ?><p class="aisa-help">Customers explicitly submit a topic from their signed-in widget. Review requests in Chat Inbox → Handoff requests. No transcript, external notification or automatic refund is sent. Changing this setting is deliberate; moving the panel does not enable intake.</p></div><?php AISA_Conversations::render_settings(); AISA_Inbox::render_preferences(); AISA_Experience::render_storage(); ?></section>
<?php } ?>
<?php if ($view==='diagnostics') { ?>
<section id="aisa-diagnostics" class="aisa-section">
                        <?php self::heading('08','Diagnostics','Observed configuration, with honest limits.'); ?>
                        <div class="aisa-card"><h3>Configuration next steps</h3><p class="aisa-help">Saved settings only · no connection tests, DNS lookups, messages or automatic changes. Check results do not certify integration readiness.</p><dl class="aisa-diagnostics aisa-config-checks"><?php foreach ($checks as $key=>$item) { ?><div data-aisa-check="<?php echo esc_attr($key); ?>"><dt><?php echo esc_html($item[0]); ?></dt><dd><?php self::badge($item[1],'warning'); ?><p class="aisa-help"><?php echo esc_html($item[2]); ?></p><a href="<?php echo esc_url(add_query_arg(array('page'=>'aisa','view'=>$item[3]),admin_url('admin.php'))); ?>">Review <?php echo esc_html($item[0]); ?> settings</a></dd></div><?php } ?></dl></div>
                        <div class="aisa-card"><h3>Environment snapshot</h3><dl class="aisa-diagnostics"><div><dt>Plugin / PHP</dt><dd><?php echo esc_html(AISA_VERSION.' / '.PHP_VERSION); ?></dd></div><div><dt>WordPress</dt><dd><?php echo esc_html(get_bloginfo('version')); ?></dd></div><div><dt>HTTPS request</dt><dd><?php self::badge(is_ssl()?'Detected':'Not detected',is_ssl()?'muted':'warning'); ?></dd></div><div><dt>Credential encryption</dt><dd><?php self::badge(function_exists('openssl_encrypt') && function_exists('openssl_decrypt')?'OpenSSL available':'OpenSSL missing','warning'); ?></dd></div><div><dt>Model credential</dt><dd><?php self::badge($modelCredential,'warning'); ?></dd></div><div><dt>Scheduled indexing</dt><dd><?php echo defined('DISABLE_WP_CRON') && DISABLE_WP_CRON?'WP-Cron disabled · use manual batches':'WP-Cron not disabled in configuration · execution unverified'; ?></dd></div><div><dt>Runtime verification</dt><dd><?php self::badge('Untested on this site','warning'); ?></dd></div></dl><p class="aisa-help">This panel reads local settings and available functions. It does not certify provider connectivity, delivery, order permissions or theme compatibility. Salt changes can make saved credentials unreadable; re-enter them after rotation.</p><details class="aisa-verification"><summary>What to verify on disposable staging</summary><ul class="aisa-feature-list"><li>Provider success, timeout, quota and invalid-response behavior.</li><li>Two-account order isolation, guest/expired login rejection and download expiry/revocation.</li><li>Admin permissions, REST cookie nonces, MCP Application Password authentication and HTTPS.</li><li>Approval after source changes; product pricing, tax/location and translated URLs.</li><li>Notification delivery with disposable destinations; keyboard, mobile, theme CSS and multisite isolation.</li></ul></details></div>
                        <div class="aisa-card"><div class="aisa-card-top"><h3>Recent audit events</h3><span class="aisa-help">Latest 15 · rolling retention</span></div><ol class="aisa-audit"><?php $events=get_posts(array('post_type'=>'aisa_audit','post_status'=>'private','posts_per_page'=>15)); foreach ($events as $event) { ?><li><time><?php echo esc_html($event->post_date); ?></time><span><?php echo esc_html($event->post_title); ?></span></li><?php } ?></ol><?php if (!$events) { ?><p class="aisa-empty">No recorded events yet. This is not proof that connections work.</p><?php } ?><p class="aisa-help">Audit events omit raw conversations, provider responses and keys. An event records an attempt; it is not a delivery receipt.</p></div>
                    </section>
<?php } ?>
                    <?php if (!in_array($view,array('diagnostics','inbox','assistant'),true)) { ?><div class="aisa-savebar"><div><strong>Your changes, deliberately applied.</strong><small>Save settings to update configuration. Connections remain untested.</small></div><button class="button button-primary" type="submit" form="aisa-settings">Save this view</button></div><?php } ?>
                </main>
            </div>
        </div>
        <?php
    }
}

