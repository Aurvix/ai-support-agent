const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
let checks = 0;
const check = (ok, label) => { checks++; assert(ok, label); };
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.listeners = {}; this.attributes = {}; this.style = { setProperty() {} }; this.hidden = false; this.disabled = false; this.value = ''; this.checked = false; }
  get textContent(){return (this._text||'')+this.children.map(n=>n.textContent||'').join('');}set textContent(v){this._text=String(v);this.children=[];}append(...nodes) { for (const node of nodes) { node.parent = this; this.children.push(node); } }
  setAttribute(key, value) { this.attributes[key] = value; }
  removeAttribute(key) { delete this.attributes[key]; }
  addEventListener(key, fn) { this.listeners[key] = fn; }
  attachShadow() { return this.shadow = new Element('shadow'); }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(node => node !== this); }
  focus() { this.focused = true; }
  click() { return this.disabled ? undefined : this.listeners.click?.(); }
}
const descendants = node => [node, ...node.children.flatMap(descendants), ...(node.shadow ? descendants(node.shadow) : [])];
const pause = () => new Promise(resolve => setImmediate(resolve));
function fixture(config = {}) {
  const body = new Element('body');
  const state = { now: Date.now(), calls: [], tickets: [], mode: 'ok', identity: false, events: {} };
  const settings = { title: 'Support', welcome: 'Welcome', stylesheet: 'https://store.test/widget.css', api: 'https://store.test/api/', customer_tickets: 1, memory: 1, loggedIn: true, nonce: 'synthetic-nonce', scope: 'scope-A', login: 'https://store.test/login', ...config };
  const window = { AISA_CONFIG: settings, crypto: webcrypto, addEventListener: (name, fn) => { state.events[name] = fn; }, setInterval: fn => { state.poll = fn; } };
  for (const key of ['localStorage', 'sessionStorage']) Object.defineProperty(window, key, { get() { throw new Error('No persistent client storage allowed'); } });
  const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => data });
  const summary = id => ({ id, topic: 'general', status: 'open', created: Math.floor(state.now / 1000), updated: Math.floor(state.now / 1000), expires: Math.floor(state.now / 1000) + 604800 });
  state.summary = summary;
  const fetch = async (url, options) => {
    const data = options.body ? JSON.parse(options.body) : null; state.calls.push({ url, options, data });
    if (url.endsWith('context')) return state.identity ? response({ code: 'identity_changed', message: 'Reload safely' }, 409) : response({ memory_enabled: true, expires: 0, ...(typeof state.ticketEnabled==='boolean'?{customer_tickets:state.ticketEnabled}:{}) });
    if (url.endsWith('memory_reset')) return response({ cleared: true });
    if (url.endsWith('tickets/status')) {
      if (state.mode === 'malformed') return response({ tickets: [{ ...summary(10), status: '<img src=x>' }] });
      if (state.mode === 'late-status') return new Promise(resolve => { state.releaseStatus = () => resolve(response({ tickets: [summary(10)] })); });
      return response({ tickets: state.tickets });
    }
    if (url.endsWith('tickets/submit')) {
      if (state.mode === 'busy') return response({ code: 'ticket_busy', message: 'Review busy' }, 409);
      if (state.mode === 'timeout') { const error = new Error('Synthetic ambiguous transport failure'); error.name = 'AbortError'; throw error; }
      if (state.mode === 'late-submit') return new Promise(resolve => { state.releaseSubmit = () => resolve(response({ ticket: summary(11), duplicate: false })); });
      const ticket = summary(11); state.tickets = [ticket]; return response({ ticket, duplicate: false });
    }
    throw new Error('Unexpected model/notification/private request: ' + url);
  };
  class Clock extends Date { static now() { return state.now; } }
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../ai-support-agent/assets/widget.js'), 'utf8'), { window, document: { body, hidden: false, getElementById: () => null, createElement: tag => new Element(tag), addEventListener() {} }, location: { origin: 'https://store.test', reload() {} }, URL, Date: Clock, AbortController, setTimeout, clearTimeout, fetch });
  const nodes = () => descendants(body);
  const button = text => nodes().find(node => node.tag === 'button' && node.textContent === text);
  const form = () => nodes().find(node => node.className === 'ticket-form');
  const submit = () => form().listeners.submit({ preventDefault() {} });
  const confirm = () => { nodes().find(node => node.tag === 'input' && node.type === 'checkbox').checked = true; };
  return { state, body, nodes, button, form, submit, confirm };
}
(async () => {
  const off = fixture({ customer_tickets: 0 }); check(!off.button('My support tickets') && !off.state.calls.length, 'Off by default has no ticket control or intake');
  const guest = fixture({ loggedIn: false, nonce: '' }); await guest.button('My support tickets').click();
  check(!guest.state.calls.some(call => call.url.includes('tickets/')) && guest.nodes().some(node => node.textContent === 'Sign in →'), 'Guest gets sign-in link without ticket lookup or submission');
  const f = fixture(); await f.button('✦ Support').click(); await f.button('My support tickets').click();
  check(f.form() && !f.state.calls.some(call => call.url.endsWith('tickets/submit')), 'Opening status creates no ticket');
  check(f.nodes().find(node => node.tag === 'input').required && f.form().children[0].tag === 'label', 'Topic and confirmation use labeled native controls');
  check(!descendants(f.form()).some(node => node.tag === 'textarea'), 'Ticket form has no transcript/message field');
  await f.submit(); check(!f.state.calls.some(call => call.url.endsWith('tickets/submit')), 'Unchecked consent never submits');
  f.confirm(); await f.submit(); const call = f.state.calls.find(item => item.url.endsWith('tickets/submit'));
  check(Object.keys(call.data).sort().join(',') === 'confirm,request_id,scope,topic' && call.data.confirm === true, 'Only exact minimal confirmed payload sent');
  check(/^[a-f0-9]{32}$/.test(call.data.request_id) && call.options.headers['X-WP-Nonce'] === 'synthetic-nonce' && call.options.cache === 'no-store', 'Ephemeral request ID, nonce and private fetch options');
  check(f.nodes().some(node => node.textContent?.startsWith('Request saved for human review.')) && !f.form(), 'Successful submission has truthful acknowledgment');
  check(f.state.calls.every(item => item.url.endsWith('context') || item.url.includes('tickets/')), 'No chat, notifications or refund call made');
  await f.button('My support tickets').click(); check(f.nodes().some(node => node.textContent === '#11 · General support'), 'Own structured ticket status displayed');
  check(!f.button('Clear chat')&&!f.button('Remember context: off'), 'Removed reset/memory UI is absent'); f.state.now+=901000; await f.state.poll(); check(!f.nodes().some(node => node.className === 'ticket-box') && !f.state.calls.some(call=>call.url.endsWith('memory_reset')), 'Display expiry removes private view without deleting account records');
  const busy = fixture(); await busy.button('My support tickets').click(); busy.confirm(); busy.state.mode = 'busy'; await busy.submit();
  check(busy.form() && !busy.button('My support tickets').disabled && !busy.nodes().some(node => node.textContent === 'Reload page'), 'Busy conflict does not impersonate identity failure');
  check(!busy.form().children.find(node => node.type === 'submit').disabled, 'Failed write permits deliberate retry');
  const ambiguous = fixture(); await ambiguous.button('My support tickets').click(); ambiguous.confirm(); ambiguous.state.mode = 'timeout'; await ambiguous.submit();
  const firstId = ambiguous.state.calls.find(item => item.url.endsWith('tickets/submit')).data.request_id;
  check(ambiguous.nodes().some(node => node.textContent?.includes('Submission may have been saved')), 'Ambiguous failure does not claim saved or unsaved success');
  ambiguous.state.mode = 'ok'; await ambiguous.submit();
  check(ambiguous.state.calls.filter(item => item.url.endsWith('tickets/submit')).every(item => item.data.request_id === firstId), 'Explicit retry reuses same submission ID');
  const late = fixture(); await late.button('✦ Support').click(); await late.button('My support tickets').click(); late.confirm(); late.state.mode = 'late-submit'; const pending = late.submit();
  while (!late.state.releaseSubmit) await pause();
  check(late.button('My support tickets').disabled, 'Concurrent ticket actions disabled while submitting');
  late.state.now+=901000; await late.state.poll(); late.state.releaseSubmit(); await pending;
  check(!late.nodes().some(node => node.textContent === '#11 · General support'), 'Late submission cannot resurrect a cleared private view');
  const logout = fixture(); await logout.button('My support tickets').click(); logout.state.mode = 'late-status'; const old = logout.button('My support tickets').click();
  while (!logout.state.releaseStatus) await pause(); logout.state.identity = true; logout.state.releaseStatus(); await old;
  check(!logout.nodes().some(node => node.textContent === '#10 · General support') && logout.nodes().some(node => node.textContent === 'Reload page'), 'Post-response identity check blocks logout/account-switch replay');
  const invalid = fixture(); invalid.state.mode = 'malformed'; await invalid.button('My support tickets').click();
  check(!invalid.form() && invalid.nodes().some(node => node.textContent?.includes('Ticket status is unavailable')), 'Unknown/malformed status never becomes displayed ticket content');
  const expired = fixture(); expired.state.tickets = [{ ...expired.state.summary(20), created: Math.floor(expired.state.now / 1000) - 604801, expires: Math.floor(expired.state.now / 1000) - 1 }];
  await expired.button('My support tickets').click(); check(!expired.nodes().some(node => node.textContent === '#20 · General support'), 'Already expired ticket hidden in client');
  const expiring = fixture(); expiring.state.tickets = [{ ...expiring.state.summary(21), created: Math.floor(expiring.state.now / 1000) - 604799, expires: Math.floor(expiring.state.now / 1000) + 1 }];
  await expiring.button('✦ Support').click(); await expiring.button('My support tickets').click(); expiring.state.now += 2000; await expiring.state.poll();
  check(!expiring.nodes().some(node => node.textContent === '#21 · General support'), 'Expiry boundary recheck clears stale private ticket view');
  const paused = fixture(); paused.state.tickets = [{ ...paused.state.summary(30), status: 'in_review', review_availability: 'unavailable' }]; await paused.button('My support tickets').click();
  check(paused.nodes().some(node => node.textContent?.startsWith('Review is paused')), 'Unavailable reviewer has truthful paused notice without changing status');
  const resolved = fixture(); resolved.state.tickets = [{ ...resolved.state.summary(31), status: 'resolved', review_availability: 'unavailable' }]; await resolved.button('My support tickets').click();
  check(!resolved.nodes().some(node=>node.textContent?.startsWith('Review is paused')) && resolved.nodes().some(node=>node.textContent?.includes('recorded resolved review status is unchanged')), 'Resolved review is not falsely presented as paused after reviewer removal');
  const disabled = fixture(); await disabled.button('My support tickets').click(); disabled.confirm(); disabled.state.ticketEnabled = false; await disabled.submit();
  check(!disabled.state.calls.some(call => call.url.endsWith('tickets/submit')) && !disabled.form() && disabled.button('My support tickets').hidden, 'Live disable closes cached form before a new submission');
  check(!disabled.nodes().some(node => node.textContent === 'Reload page') && disabled.nodes().some(node => node.textContent?.startsWith('Account-linked tickets are disabled.')), 'Disable is not an identity failure and explains retained deadlines');
  const inFlight = fixture(); await inFlight.button('My support tickets').click(); inFlight.confirm(); inFlight.state.mode='late-submit'; const accepted=inFlight.submit();
  while (!inFlight.state.releaseSubmit) await pause(); inFlight.state.ticketEnabled=false; inFlight.state.releaseSubmit(); await accepted;
  check(!inFlight.nodes().some(node => node.className === 'ticket-row') && inFlight.button('My support tickets').hidden, 'Post-response disable never restores a hidden ticket view');
  inFlight.state.ticketEnabled=true; await inFlight.button('✦ Support').click();
  check(!inFlight.button('My support tickets').hidden && !inFlight.state.calls.slice(-1).some(call=>call.url.endsWith('tickets/submit')), 'Reenable reveals explicit control without auto-submission');
  console.log(`PASS: ${checks} widget account-ticket consent/minimality/identity/opt-out/expiry/failure/retry checks; synthetic fetch only.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
