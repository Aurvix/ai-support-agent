const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const Parser = require('php-parser');
const parser = new Parser({ parser: { phpVersion: '8.1', suppressErrors: false } });
const base = path.resolve(__dirname, '../ai-support-agent');
function files(dir) { return fs.readdirSync(dir, { withFileTypes: true }).flatMap(e => e.isDirectory() ? files(path.join(dir, e.name)) : [path.join(dir, e.name)]); }
let count = 0;
for (const file of files(base)) {
  if (file.endsWith('.php')) { parser.parseCode(fs.readFileSync(file, 'utf8'), file); count++; }
  if (file.endsWith('.js')) { new vm.Script(fs.readFileSync(file, 'utf8'), { filename: file }); count++; }
}
const php = fs.readFileSync(path.join(base, 'includes/class-agent.php'), 'utf8');
const js = fs.readFileSync(path.join(base, 'assets/widget.js'), 'utf8');
assert(!js.includes('innerHTML'), 'Untrusted model text must never be interpreted as HTML');
assert(php.includes("'customer_id'=>get_current_user_id()"), 'Orders must bind to the authenticated account');
assert(php.includes('wc_get_customer_available_downloads(get_current_user_id())'), 'Use WooCommerce download permissions');
assert(php.includes('wp_safe_remote_post'), 'Outbound HTTP must use safe URL validation');
assert(php.includes("check_admin_referer('aisa_admin')"), 'Admin actions need CSRF protection');
assert(php.includes("'post_type'=>'aisa_draft','post_status'=>'pending'"), 'Learning must wait for review');
assert(php.includes("'enabled'=>0"), 'Chat is opt-in');
assert(!php.includes("'callback'=>'__return_true'"), 'No public callback can skip application logic');
console.log(`PASS: parsed ${count} production PHP/JS files and checked 8 static assertions. Runtime staging tests remain required.`);
