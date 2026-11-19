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
  const state = { now: 1800000000000, scope: 'scope-A', expires: 0, calls: [], mode: 'ok', archiveEnabled: false, events: {}, documentEvents: {}, ...overrides };
  const config = { scope: state.scope, memory: 1, stylesheet: 'https://store.test/widget.css', api: 'https://store.test/api/', title: 'Support', welcome: 'Welcome', loggedIn: true, nonce: 'synthetic-nonce', archive_enabled: state.archiveEnabled, ...state.config };
  const window = { AISA_CONFIG: config, crypto: webcrypto, matchMedia:()=>({matches:state.reduced!==false}), addEventListener: (name, fn) => { state.events[name] = fn; }, setInterval: fn => { state.poll = fn; } };
  for (const name of ['localStorage', 'sessionStorage']) Object.defineProperty(window, name, { get() { throw new Error('Browser persistence is forbidden'); } });
  const document = { body, hidden: false, getElementById: () => null, createElement: tag => new Element(tag), addEventListener: (name, fn) => { state.documentEvents[name] = fn; } };
  const response = (value, status = 200) => ({ ok: status === 200, status, json: async () => value });
  const fetch = async (url, options) => {
    const name = url.split('/').pop(); const data = options.body ? JSON.parse(options.body) : null; state.calls.push({ name, data, signal: options.signal });
    if (name === 'context') {
      if (state.contextPause) return new Promise(resolve => { state.resolveContext = () => resolve(response({ code: 'identity_changed', message: 'Changed' }, 409)); });
      if (data.scope !== state.scope) return response({ code: 'identity_changed', message: 'Changed' }, 409);
      return response({ archive_enabled: state.archiveEnabled, memory_enabled: true, expires: state.expires, expired: false });
    }
    if (name === 'conversation_poll') {
      if (state.liveFail) return response({message:'Status failed'},503);
      const events=(state.liveEvents||[]).filter(x=>x.sequence>data.after);
      return response({mode:state.liveMode||'ai',generation:state.liveGeneration||1,pending:state.mode==='pending',events,cursor:Math.max(0,...(state.liveEvents||[]).map(x=>x.sequence)),reviewer_available:state.reviewerAvailable!==false});
    }
    if (name === 'conversation_message') return state.humanSaveFail ? response({saved:false}) : response({saved:true,duplicate:false});
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
module.exports={fixture};
if(require.main===module)(async()=>{
 const off=fixture();await off.open();const offLabel=off.nodes().find(n=>n.className==='archive-permission');check(offLabel&&offLabel.hidden,'capture option absent while site mode is off');await off.submit('Public question');check(!Object.hasOwn(off.state.calls.find(c=>c.name==='chat').data,'archive_consent'),'default-off request contract unchanged');
 const app=fixture({archiveEnabled:true});await app.open();const label=app.nodes().find(n=>n.className==='archive-permission'),consent=label.children[0];check(!label.hidden&&consent.checked===false,'customer consent starts unchecked');check(label.children[1].textContent.includes('AI-assisted review'),'consent describes AI-assisted review');check(!/retention|expires|days/i.test(label.children[1].textContent),'no technical storage-duration labels in customer consent');
 await app.submit('A public FAQ');check(!Object.hasOwn(app.state.calls.filter(c=>c.name==='chat').at(-1).data,'archive_consent'),'opening chat or sending alone never opts in');consent.checked=true;await app.submit('Another public FAQ');let sent=app.state.calls.filter(c=>c.name==='chat').at(-1).data;check(sent.archive_consent===true,'explicit checked consent sent for this request');check(typeof sent.session==='string'&&sent.session.length===32&&sent.scope==='scope-A','archive uses existing bounded session/scope');check(sent.remember===false,'archive does not opt customer into public context memory');
 const previousSession=sent.session;consent.checked=false;await app.submit('Do not save this question');check(!Object.hasOwn(app.state.calls.filter(c=>c.name==='chat').at(-1).data,'archive_consent'),'unchecking stops future capture requests');consent.checked=true;app.state.archiveEnabled=false;await app.state.poll();await pause();await pause();check(label.hidden&&consent.checked===false,'server-side disable hides and revokes stale checkbox');await app.submit('Mode is off now');check(!Object.hasOwn(app.state.calls.filter(c=>c.name==='chat').at(-1).data,'archive_consent'),'disabled capture is not sent despite stale page config');
 const changed=fixture({archiveEnabled:true});await changed.open();const changedLabel=changed.nodes().find(n=>n.className==='archive-permission');changedLabel.children[0].checked=true;changed.state.scope='scope-B';await changed.state.events.focus();check(changedLabel.children[0].checked===false,'identity change clears prior consent');const before=changed.state.calls.filter(c=>c.name==='chat').length;await changed.submit('Other account');check(changed.state.calls.filter(c=>c.name==='chat').length===before,'identity failure blocks stale-session submission');
 for(const target of [off,app,changed]){check(!target.nodes().some(n=>n.tag==='button'&&/delete chat|clear chat/i.test(n.textContent||'')),'no customer chat delete control');check(!target.state.calls.some(c=>/delete|archive_reset|conversations/.test(c.name)),'no customer transcript deletion route called');}
 const live=fixture({archiveEnabled:true,mode:'pending'});await live.open();const liveConsent=live.nodes().find(n=>n.className==='archive-permission').children[0];liveConsent.checked=true;const flight=live.submit('Synthetic public question');await pause();await pause();check(!!live.state.resolveChat,'AI fixture request is in flight');live.state.liveMode='human';live.state.liveGeneration=2;live.state.liveEvents=[{sequence:2,kind:'notice',text:'AI reply stopped because support took over.'},{sequence:4,kind:'human',text:'HUMAN_REPLY_CANARY <img src=x onerror=alert(1)>'}];await live.state.poll();check(live.state.calls.find(c=>c.name==='chat').signal.aborted,'takeover aborts pending browser request');live.state.resolveChat();await flight;check(!live.nodes().some(n=>(n.textContent||'').includes('LATE_MODEL_CANARY')),'late AI answer never rendered after takeover');check(live.nodes().some(n=>(n.textContent||'').includes('HUMAN_REPLY_CANARY <img')),'human text rendered literally');const humanCount=()=>live.nodes().filter(n=>n.className==='message-body'&&(n.textContent||'').startsWith('HUMAN_REPLY_CANARY')).length;check(humanCount()===1,'human reply appears once');await live.state.poll();check(humanCount()===1,'poll cursor suppresses duplicate human reply');const aiBefore=live.state.calls.filter(c=>c.name==='chat').length;await live.submit('Message for the human agent');check(live.state.calls.filter(c=>c.name==='chat').length===aiBefore,'human-mode submission makes no model request');const humanCall=live.state.calls.find(c=>c.name==='conversation_message');check(humanCall&&humanCall.data.archive_consent===true&&/^[a-f0-9]{32}$/.test(humanCall.data.request_id),'human send has explicit consent and bounded idempotence ID');const beforeWithdraw=live.state.calls.length;liveConsent.checked=false;await live.submit('Do not save this');check(live.state.calls.length===beforeWithdraw,'withdrawal blocks human send and does not silently send to AI');liveConsent.checked=true;live.state.liveMode='closed';live.state.liveGeneration=3;await live.state.poll();check(live.form.children[1].disabled,'resolved chat disables send');
 const missing=fixture({archiveEnabled:true});await missing.open();missing.nodes().find(n=>n.className==='archive-permission').children[0].checked=true;missing.state.liveMode='human';missing.state.liveGeneration=2;missing.state.reviewerAvailable=false;await missing.state.poll();check(missing.form.children[1].disabled,'missing reviewer prevents customer send');check(missing.nodes().some(n=>(n.textContent||'').includes('currently unavailable')),'missing reviewer status is honest');
 const failed=fixture({archiveEnabled:true});await failed.open();failed.nodes().find(n=>n.className==='archive-permission').children[0].checked=true;failed.state.liveFail=true;await failed.state.poll();check(failed.form.hidden,'failed control check hides composer instead of resuming AI');check(!failed.state.calls.some(c=>c.name==='chat'),'failed status does not call provider');
 console.log('PASS: '+checks+' consent/takeover/late-reply/human-message/identity/no-customer-delete checks (synthetic DOM/fetch).');
})().catch(error=>{console.error(error);process.exitCode=1;});
