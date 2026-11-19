<?php
/**
 * Plugin Name: AI Support Agent
 * Description: Site-specific AI support, WooCommerce assistance and reviewable knowledge.
 * Version: 0.1.7
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: ai-support-agent
 */
defined('ABSPATH') || exit;
define('AISA_DIR', plugin_dir_path(__FILE__));
define('AISA_URL', plugin_dir_url(__FILE__));
define('AISA_VERSION', '0.1.7');
require_once AISA_DIR . 'includes/class-inbox.php';
add_action('plugins_loaded', array('AISA_Inbox', 'boot'));
require_once AISA_DIR . 'includes/class-handoff.php';
add_action('plugins_loaded', array('AISA_Handoff', 'boot'));
require_once AISA_DIR . 'includes/class-memory.php';
require_once AISA_DIR . 'includes/class-admin.php';
require_once AISA_DIR . 'includes/class-agent.php';
add_action('plugins_loaded', array('AISA_Agent', 'boot'));
register_activation_hook(__FILE__, array('AISA_Agent', 'activate'));
register_deactivation_hook(__FILE__, function() { wp_clear_scheduled_hook('aisa_index_batch'); wp_clear_scheduled_hook(AISA_Inbox::HOOK); });
