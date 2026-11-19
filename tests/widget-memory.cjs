const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
let checks = 0;
const check = (value, label) => { checks++; assert(value, label); };
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.listeners = {}; this.attributes = {}; this.style = { setProperty() {} }; this.hidden = false; this.disabled = false; this.value = ''; }
  append(...nodes) { for (const node of nodes) { node.parent = this; this.children.push(node); } }
  setAttribute(key, value) { this.attributes[key] = value; }
  removeAttribute(key) { delete this.attributes[key]; }
  addEventListener(key, fn) { this.listeners[key] = fn; }
  attachShadow() { return this.shadow = new Element('shadow'); }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(node => node !== this); }
  focus() { this.focused = true; }
}
const descendants = node => [node, ...node.children.flatMap(descendants), ...(node.shadow ? descendants(node.shadow) : [])];
const pause = () => new Promise(resolve => setImmediate(resolve));
function fixture(overrides = {}) {
  const body = new Element('body');
  const state = { now: 1800000000000, scope: 'scope-A', expires: 0, calls: [], mode: 'ok', events: {}, documentEvents: {}, ...overrides };
  const config = { scope: state.scope, memory: 1, stylesheet: 'https://store.test/widget.css', api: 'https://store.test/api/', title: 'Support', welcome: 'Welcome', loggedIn: true, nonce: 'synthetic-nonce', ...state.config };
  const window = { AISA_CONFIG: config, crypto: webcrypto, addEventListener: (name, fn) => { state.events[name] = fn; }, setInterval: fn => { state.poll = fn; } };
  for (const name of ['localStorage', 'sessionStorage']) Object.defineProperty(window, name, { get() { throw new Error('Browser persistence is forbidden'); } });
  const document = { body, hidden: false, getElementById: () => null, createElement: tag => new Element(tag), addEventListener: (name, fn) => { state.documentEvents[name] = fn; } };
  const response = (value, status = 200) => ({ ok: status === 200, status, json: async () => value });
  const fetch = async (url, options) => {
    const name = url.split('/').pop(); const data = options.body ? JSON.parse(options.body) : null; state.calls.push({ name, data, signal: options.signal });
    if (name === 'context') {
      if (state.contextPause) return new Promise(resolve => { state.resolveContext = () => resolve(response({ code: 'identity_changed', message: 'Changed' }, 409)); });
      if (data.scope !== state.scope) return response({ code: 'identity_changed', message: 'Changed' }, 409);
      return response({ memory_enabled: true, expires: state.expires, expired: false });
    }
    if (name === 'chat') {
      if (state.mode === 'pending') return new Promise(resolve => { state.resolveChat = () => resolve(response({ reply: 'LATE_MODEL_CANARY', memory: { saved: true, expires: state.now / 1000 + 900 } })); });
      if (state.mode === 'fail') return response({ message: 'Provider unavailable' }, 503);
      state.expires = Math.floor(state.now / 1000) + 900;
      return response({ reply: 'PUBLIC_ANSWER_CANARY', products: [], sources: [], memory: { saved: data.remember, expires: state.expires } });
    }
    if (name === 'memory_reset') { if (state.mode === 'resetFail') return response({ message: 'Temporary failure' }, 503); state.expires = 0; return response({ cleared: true }); }
    if (name === 'orders') return response({ orders: [{ number: 'PRIVATE_ORDER_A', status: 'Completed', items: [], url: 'https://store.test/order/private-a' }] });
    if (name === 'downloads') return response({ downloads: [{ name: 'PRIVATE_DOWNLOAD_A', url: 'https://store.test/?download_file=private-a&order=secret-canary' }] });
    throw new Error('Unexpected route ' + name);
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../ai-support-agent/assets/widget.js'), 'utf8'), { window, document, fetch, location: { origin: 'https://store.test', reload() { state.reloaded = true; } }, URL, AbortController, setTimeout, clearTimeout, Date: { now: () => state.now } });
  const nodes = () => descendants(body);
  const button = text => nodes().find(node => node.tag === 'button' && node.textContent === text);
  const form = nodes().find(node => node.className === 'composer');
  return { state, config, nodes, button, form, open: () => nodes().find(node => node.className === 'launch').listeners.click(), submit: async text => { form.children[0].value = text; return form.listeners.submit({ preventDefault() {} }); } };
}
(async () => {
  const app=fixture();await app.open();
  for(const label of ['Clear chat','Remember context: off','Remember context: on','Talk to a person','Payment options','Suggest an improvement'])check(!app.button(label),'Removed UI control absent: '+label);
  check(!app.state.calls.some(c=>c.name==='memory_reset'),'Mount/open does not clear retained history');
  await app.submit('First public question');const sent=app.state.calls.find(c=>c.name==='chat').data;
  check(sent.remember===false,'Removed memory opt-in leaves new UI turns stateless');
  check(Object.keys(sent).sort().join(',')==='message,remember,scope,session','No transcript or prior response sent');
  await app.button('My purchases').listeners.click();check(app.nodes().some(n=>n.textContent==='PRIVATE_DOWNLOAD_A')&&!app.nodes().some(n=>n.href?.includes('download_file=')),'Owned metadata displayed without exposing legacy file link');
  await app.submit('Another public question');check(app.state.calls.filter(c=>c.name==='chat').every(c=>!JSON.stringify(c.data).includes('secret-canary')),'Private links never replayed into chat');
  app.state.now+=901000;await app.state.poll();check(!app.nodes().some(n=>n.href?.includes('private-a')||n.textContent==='PUBLIC_ANSWER_CANARY'),'Expiry clears stale private display');
  await app.submit('New question at expiry');check(app.state.calls.filter(c=>c.name==='chat').length===3,'An explicit question after expiry is not lost');
  app.state.mode='fail';await app.submit('Failure');check(!app.form.children[0].disabled&&!app.form.children[1].disabled,'Failure releases composer');
  check(app.nodes().some(n=>n.textContent==='Provider unavailable'),'Failure is truthful');
  const late=fixture();await late.open();late.state.mode='pending';const pending=late.submit('Before expiry');while(!late.state.resolveChat)await pause();late.state.now+=901000;await late.state.poll();late.state.resolveChat();await pending;
  check(!late.nodes().some(n=>n.textContent==='LATE_MODEL_CANARY'),'Late model response cannot resurrect expired display');
  const identity=fixture();await identity.open();await identity.button('My purchases').listeners.click();identity.state.contextPause=true;const focused=identity.state.events.focus();check(identity.nodes().find(n=>n.className==='messages').hidden,'Revalidation hides private content first');identity.state.resolveContext();await focused;
  check(!identity.nodes().some(n=>n.href?.includes('private-a')||n.textContent?.includes('PRIVATE_ORDER_A')),'Identity switch removes old private content');check(identity.form.hidden&&!!identity.button('Reload page'),'Identity transition requires fresh nonce/page');
  const other=fixture({scope:'scope-B'});await other.open();check(!other.nodes().some(n=>n.textContent?.includes('PRIVATE_ORDER_A')),'New identity starts clean');await other.submit('Other site question');check(other.state.calls.find(c=>c.name==='chat').data.scope==='scope-B','Separate scope is sent');
  const bounded=fixture();await bounded.open();for(let i=0;i<25;i++)await bounded.submit('Visible question '+i);check(bounded.nodes().find(n=>n.className==='messages').children.length<=40,'Visible display remains bounded');check(!bounded.nodes().some(n=>n.textContent==='Visible question 0'),'Old display nodes discarded');
  check(!bounded.state.calls.some(c=>c.name==='memory_reset'),'UI removal never triggers automatic server deletion');
  console.log('PASS: '+checks+' stateless widget/retained-memory/privacy/expiry/identity/failure checks; backend memory isolation remains separately tested.');
})().catch(error=>{console.error(error);process.exitCode=1;});
