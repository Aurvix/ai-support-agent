=== AI Support Agent ===
Contributors: aurvix
Tags: support, chatbot, woocommerce, knowledge, handoff
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.1.45
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Configurable website support, public knowledge and private account-owned WooCommerce assistance.

== Description ==
Provider/model selection, reviewed knowledge, bounded public reference sync, configurable widget, consent-controlled Inbox and human takeover. This is reference retrieval, not model-weight training. Use your own provider credentials; account/model availability and charges depend on that provider.

== Installation ==
Download ai-support-agent-0.1.45.zip from https://github.com/Aurvix/ai-support-agent/releases/latest and upload it through WordPress Plugins. GitHub's generated source ZIP is not the installable plugin package. Configure Providers, Training and Widget before enabling customer chat. WooCommerce is optional except for commerce features.

Update through WordPress's replace flow. Do not delete the old plugin first: Delete removes saved conversations. Existing settings, keys and exclusions are preserved by an update.

== Data handling ==
Customer chat, capture, memory and MCP are opt-in on fresh installs. Relevant questions/public references may be transmitted to the configured AI provider after enablement. Credentials stay server-side. Ordinary permitted public posts/pages/products sync automatically; private/account/order data and unreviewed imports do not become public knowledge. Verify your own restrictions, provider policies and native download/file hosting.

Default uninstall removes current-site saved chats, unreviewed chat excerpts and attributable admin sessions. Settings/approved knowledge/structured tickets use a separate full-removal opt-in. Unattributed legacy temporary caches expire separately. External provider copies/backups are not erased by plugin uninstall.

== Changelog ==
= 0.1.45 =
First public GPL source distribution; complete license, public installation/development documentation and redistributable test fixtures. Existing0.1.44 runtime behavior retained.

== Support ==
https://github.com/Aurvix/ai-support-agent/issues
Use fictional data only. Never post credentials or private customer information.
