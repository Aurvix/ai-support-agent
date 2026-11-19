<?php
define('WP_INSTALLING',true);
require __DIR__.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
make_db_current_silent();
populate_options();
populate_roles();
update_option('siteurl','https://aisa.local.test');
update_option('home','https://aisa.local.test');
update_option('blogname','Isolated synthetic store');
update_option('blog_public',0);
update_option('admin_email','nobody@example.test');
// No wp_install(), user passwords, login cookies or Application Passwords are created.
foreach (array('fixture_admin'=>'administrator','fixture_a'=>'customer','fixture_b'=>'customer') as $login=>$role) {
    $wpdb->insert($wpdb->users,array('user_login'=>$login,'user_pass'=>'*','user_nicename'=>$login,'user_email'=>$login.'@example.test','user_registered'=>gmdate('Y-m-d H:i:s'),'display_name'=>$login));
    if (!$wpdb->insert_id) { throw new RuntimeException('Could not create disabled-login synthetic user: '.$wpdb->last_error); }
    $user=new WP_User($wpdb->insert_id); $user->set_role($role==='customer'?'subscriber':$role);
}
wp_set_current_user(1);
$result=activate_plugin('woocommerce/woocommerce.php','',false,false);
if (is_wp_error($result)) { throw new RuntimeException('Woo activation failed: '.$result->get_error_message()); }
$result=activate_plugin('ai-support-agent/ai-support-agent.php','',false,false);
if (is_wp_error($result)) { throw new RuntimeException('Agent activation failed: '.$result->get_error_message()); }
echo wp_json_encode(array('phase'=>'bootstrap','wp'=>$wp_version,'woo'=>defined('WC_VERSION')?WC_VERSION:null,'engine'=>DB_ENGINE,'active'=>get_option('active_plugins')));
