const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.listeners = {}; this.attributes = {}; this.style = { setProperty() {} }; this.hidden = false; this.value = ''; }
  append(...children) { for (const c of children) { c.parent = this; this.children.push(c); } }
  setAttribute(k, v) { this.attributes[k] = v; }
  removeAttribute(k) { delete this.attributes[k]; }
  addEventListener(k, fn) { this.listeners[k] = fn; }
  attachShadow() { return this.shadow = new Element('shadow'); }
  remove() { this.parent.children = this.parent.children.filter(n => n !== this); }
  focus() { this.focused = true; }
}
const descendants = n => [n, ...n.children.flatMap(descendants), ...(n.shadow ? descendants(n.shadow) : [])];
async function run(loggedIn) {
  const body = new Element('body'); const requests = [];
  const config = { stylesheet: 'https://store.test/custom-plugin/assets/widget.css?ver=0.1.1', api: 'https://store.test/wp-json/aisa/v1/', title: 'Support', welcome: 'Welcome', position: 'right', color: '#635bff', radius: 20, loggedIn, nonce: loggedIn ? 'test-nonce' : '', login: 'https://store.test/login', downloads_page: 'https://store.test/my-account/downloads/', learning: 0 };
  const context = { window: { AISA_CONFIG: config }, document: { body, scripts: [{ src: 'https://store.test/unrelated/assets/widget.js?ver=1' }], getElementById: () => null, createElement: tag => new Element(tag) }, location: { origin: 'https://store.test' }, URL, AbortController, setTimeout, clearTimeout, fetch: async (url, options) => {
    requests.push({ url, options });
    const result = url.endsWith('orders') ? { orders: [{ number: '42', status: 'Completed', items: [{ name: 'Owned product', quantity: 1 }], url: 'https://store.test/order/42' }] } : url.endsWith('downloads') ? { downloads: [{ name: 'Owned download', url: 'https://store.test/download/signed' }] } : { reply: '<img src=x onerror=alert(1)>', products: [{ name: 'Example', price: '$5', description: 'Details', url: 'javascript:alert(1)', available: true }], sources: [] };
    return { ok: true, json: async () => result };
  } };
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../ai-support-agent/assets/widget.js'), 'utf8'), context);
  const host = body.children.find(n => n.id === 'aisa-host');
  const stylesheet = descendants(host.shadow).filter(n => n.tag === 'link');
  assert.equal(stylesheet.length, 1, 'Exactly one widget stylesheet in the ShadowRoot');
  assert.equal(stylesheet[0].href, config.stylesheet, 'Use the versioned server URL, not another plugin script');
  const lightNodes = n => [n, ...n.children.flatMap(lightNodes)];
  assert(!lightNodes(body).some(n => n.tag === 'link' || n.tag === 'style'), 'Widget must not insert global styles');
  const nodes = () => descendants(body);
  const launch = nodes().find(n => n.className === 'launch'); const panel = nodes().find(n => n.className === 'panel');
  assert.equal(panel.hidden, true); await launch.listeners.click(); assert.equal(panel.hidden, false);
  assert.equal(launch.attributes['aria-expanded'], 'true');
  const composer = nodes().find(n => n.className === 'composer'); const input = composer.children[0]; input.value = 'Tell me about this product';
  await composer.listeners.submit({ preventDefault() {} });
  assert(nodes().some(n => n.textContent === '<img src=x onerror=alert(1)>'), 'Model output remains literal text');
  assert.equal(nodes().find(n => n.tag === 'a' && n.textContent==='Example').href, '', 'Unsafe URL rejected');
  assert.equal(JSON.parse(requests.find(r => r.url.endsWith('chat')).options.body).message, 'Tell me about this product');
  assert.equal(composer.children[1].disabled, false);
  const purchases = nodes().find(n => n.textContent === 'My purchases'); await purchases.listeners.click();
  if (loggedIn) {
    assert(requests.some(r => r.url.endsWith('orders') && r.options.headers['X-WP-Nonce'] === 'test-nonce'));
    assert(nodes().some(n => n.textContent === 'My Downloads →'));
  } else { assert.equal(requests.filter(r => !r.url.endsWith('context')).length, 1); assert(nodes().some(n => n.textContent === 'My Downloads →')); }
  nodes().find(n => n.className === 'close').listeners.click(); assert.equal(panel.hidden, true);
}
async function failureFlow(mode) {
  const body = new Element('body');
  const config = { stylesheet: 'https://store.test/widget.css', api: 'https://store.test/wp-json/aisa/v1/', title: 'Support', welcome: 'Welcome' };
  const context = { window: { AISA_CONFIG: config }, document: { body, getElementById: () => null, createElement: tag => new Element(tag) }, location: { origin: 'https://store.test' }, URL, AbortController, setTimeout, clearTimeout, fetch: async url => {
    if (url.endsWith('context')) return { ok: true, json: async () => ({ memory_enabled: false, expires: 0 }) };
    if (mode === 'timeout') { const error = new Error('raw network detail'); error.name = 'AbortError'; throw error; }
    if (mode === 'bad-json') return { ok: false, json: async () => { throw new Error('Malformed response'); } };
    return { ok: false, json: async () => ({ message: 'Please use human support.' }) };
  } };
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../ai-support-agent/assets/widget.js'), 'utf8'), context);
  const nodes = descendants(body); const form = nodes.find(n => n.className === 'composer');
  form.children[0].value = 'Synthetic public question';
  await form.listeners.submit({ preventDefault() {} });
  assert.equal(form.children[0].disabled, false, mode + ': input recovers');
  assert.equal(form.children[1].disabled, false, mode + ': send recovers');
  assert.equal(nodes.find(n => n.className === 'panel').attributes['aria-busy'], undefined, mode + ': busy status clears');
  const expected = mode === 'timeout' ? 'The request timed out. It may still finish on the server; no automatic retry.' : mode === 'bad-json' ? 'Support returned an unreadable response. Please retry or use contact options.' : 'Please use human support.';
  assert(descendants(body).some(n => n.textContent === expected), mode + ': useful error shown');
}
async function openingRecovery() {
  const body = new Element('body'); let mode = 'pending', release;
  const config = { stylesheet: 'https://store.test/widget.css', api: 'https://store.test/api/', title: 'Support', welcome: 'Welcome' };
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../ai-support-agent/assets/widget.js'), 'utf8'), {
    window: { AISA_CONFIG: config }, document: { body, getElementById: () => null, createElement: tag => new Element(tag) }, location: { origin: 'https://store.test' }, URL, AbortController, setTimeout, clearTimeout,
    fetch: async () => { if (mode === 'fail') throw new TypeError('Synthetic connection failure'); const response = { ok: true, json: async () => ({ memory_enabled: false, expires: 0 }) }; return mode === 'pending' ? new Promise(resolve => { release = () => resolve(response); }) : response; }
  });
  const nodes = () => descendants(body), launch = nodes().find(n => n.className === 'launch'), panel = nodes().find(n => n.className === 'panel');
  const opening = launch.listeners.click(); nodes().find(n => n.className === 'close').listeners.click(); release(); await opening;
  assert(panel.hidden && launch.attributes['aria-expanded'] === 'false', 'Late context success keeps a deliberately closed panel closed');
  assert(!nodes().some(n => n.textContent === 'Try again'), 'Late context success does not invent retry UI');
  mode = 'fail'; await launch.listeners.click();
  assert.equal(nodes().filter(n => n.textContent === 'Try again').length, 1, 'Connection failure exposes one deliberate retry');
  assert(nodes().find(n => n.className === 'composer').hidden, 'Failed handshake keeps composer hidden');
  mode = 'ok'; await nodes().find(n => n.textContent === 'Try again').listeners.click();
  assert(!nodes().some(n => n.textContent === 'Try again'), 'Successful retry removes obsolete retry controls');
  assert(!nodes().find(n => n.className === 'composer').hidden, 'Successful retry restores composer');
}
(async () => {
  await run(false); await run(true);
  for (const mode of ['timeout', 'unavailable', 'bad-json']) await failureFlow(mode);
  await openingRecovery();
  console.log('PASS: widget guest/account, isolated CSS, literal rendering, unsafe URLs, nonce transport and timeout/failure recovery flows; 6 opening/close/retry regression checks (DOM doubles).');
})().catch(e => { console.error(e); process.exitCode = 1; });
