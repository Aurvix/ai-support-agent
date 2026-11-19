const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
let checks=0;const check=(ok,label)=>{checks++;assert(ok,label);};
class Element{
 constructor(tag){this.tag=tag;this.children=[];this.listeners={};this.attributes={};this.hidden=false;this.disabled=false;this.style={setProperty(){}};this.value='';}
 get textContent(){return (this._text||'')+this.children.map(n=>n.textContent||'').join('');}set textContent(v){this._text=String(v);this.children=[];}append(...nodes){for(const n of nodes){n.parent=this;this.children.push(n);}}
 setAttribute(k,v){this.attributes[k]=v;}removeAttribute(k){delete this.attributes[k];}addEventListener(k,fn){this.listeners[k]=fn;}
 attachShadow(){this.shadow=new Element('shadow');this.shadow.parent=this;return this.shadow;}
 remove(){if(this.parent)this.parent.children=this.parent.children.filter(n=>n!==this);}focus(){this.focused=true;}
}
const nodes=n=>[n,...n.children.flatMap(nodes),...(n.shadow?nodes(n.shadow):[])];
const visible=n=>!n.hidden&&(!n.parent||visible(n.parent));
function fixture(config={}){
 const body=new Element('body'),state={calls:[],mode:'ok',context:'ok',events:{}};
 const c={api:'https://store.test/api/',stylesheet:'https://store.test/widget.css',title:'Support',welcome:'Welcome',scope:'synthetic-scope',nonce:'synthetic-not-a-cookie',loggedIn:true,commerce:true,support_email:'support@example.test',handoff_url:'https://store.test/contact',...config};
 const response=(data,status=200)=>({ok:status<400,status,json:async()=>data});
 const fetch=async(url,options)=>{
  const name=url.split('/').pop();state.calls.push({name,body:options.body?JSON.parse(options.body):null});
  if(name==='context'){if(state.context==='failed')throw new TypeError('Synthetic failure');if(state.context==='identity')return response({code:'identity_changed',message:'Changed'},409);return response({memory_enabled:false,expires:0});}
  if(name==='orders'){if(state.mode==='bad-auth-json')return{ok:false,status:401,json:async()=>{throw Error('raw-parser-detail');}};if(state.mode==='orders-fail')return response({message:'Unavailable'},503);if(state.mode==='identity')return response({message:'Denied'},401);if(state.mode==='malformed')return response({orders:null});if(state.mode==='bad-items')return response({orders:[{number:'PRIVATE_ORDER',status:'Completed',items:[null],url:'https://store.test/private-order'}]});return response({orders:[{number:'PRIVATE_ORDER',status:'Completed',items:[],url:'https://store.test/private-order'}]});}
  if(name==='downloads'){if(state.mode==='downloads-fail')return response({message:'Unavailable'},503);return response({downloads:state.mode==='empty-downloads'?[]:[{name:'PRIVATE_DOWNLOAD',url:'https://store.test/?download_file=synthetic-secret'}],account_url:state.accountUrl});}
  if(name==='chat'){if(state.mode==='bad-chat')return response({reply:null});if(state.mode==='provider-fail')return response({code:'provider',message:'Assistant unavailable'},503);if(state.mode==='knowledge-changed')return response({code:'knowledge_changed',message:'Approved information changed. Retry.'},409);return response({reply:'Synthetic public answer',products:[],sources:[]});}
  if(name==='handoff')return response({message:'Use the support contact so the team can reply.'});
  throw Error('Unexpected action '+name);
 };
 vm.runInNewContext(fs.readFileSync(process.argv.find(x=>x.startsWith('--source='))?.slice(9)||path.join(__dirname,'../ai-support-agent/assets/widget.js'),'utf8'),{window:{AISA_CONFIG:c,addEventListener:(n,fn)=>state.events[n]=fn},document:{body,getElementById:()=>null,createElement:t=>new Element(t)},location:{origin:'https://store.test'},URL,AbortController,setTimeout,clearTimeout,fetch});
 const all=()=>nodes(body),button=t=>all().find(n=>n.tag==='button'&&n.textContent===t),find=cls=>all().find(n=>n.className===cls);
 return{state,all,button,find,open:()=>button('✦ Support').listeners.click(),click:t=>button(t).listeners.click(),submit:async()=>{const f=find('composer');f.children[0].value='Synthetic public question';await f.listeners.submit({preventDefault(){}});}};
}
(async()=>{
 const offline=fixture();offline.state.context='failed';await offline.open();
 check(offline.find('recovery')&&!offline.find('recovery').hidden&&offline.find('messages').hidden,'Context failure shows recovery, hides history');
 check(offline.all().some(n=>n.href==='https://store.test/contact'&&visible(n)),'Public contact remains available without context service');
 check(offline.state.calls.every(c=>c.name==='context'),'Recovery sends no handoff, model or account action');
 offline.state.context='ok';await offline.click('Try again');check(offline.find('recovery').hidden&&!offline.find('composer').hidden,'Successful explicit retry restores verified interaction');
 const cached=fixture();await cached.open();await cached.click('My purchases');check(cached.all().some(n=>n.className==='bubble agent'&&n.textContent?.startsWith('PRIVATE_DOWNLOAD')&&visible(n)),'Synthetic own download visible after successful checks');
 cached.state.context='failed';await cached.state.events.focus();
 check(!cached.all().some(n=>n.href?.includes('download_file=')&&visible(n)),'Failed revalidation never reveals cached private links');
 check(cached.find('actions').hidden&&cached.find('composer').hidden,'Failed revalidation hides account and composer controls');
 cached.state.context='identity';await cached.click('Try again');check(!cached.all().some(n=>n.href?.includes('download_file='))&&cached.button('Reload page'),'Changed identity clears cached private results and requires reload');
 const partial=fixture();await partial.open();partial.state.mode='orders-fail';await partial.click('My purchases');
 check(partial.all().some(n=>n.className==='bubble agent'&&n.textContent?.startsWith('PRIVATE_DOWNLOAD')),'Order failure preserves separately successful owned downloads');
 check(partial.all().some(n=>n.textContent?.startsWith('Orders are temporarily unavailable.')),'Order failure is explicitly distinguished from no purchases');
 partial.state.mode='downloads-fail';await partial.click('My purchases');
 check(partial.all().some(n=>n.textContent?.startsWith('Order #PRIVATE_ORDER')),'Download failure preserves separately successful owned orders');
 check(partial.all().some(n=>n.textContent?.startsWith('Downloads are temporarily unavailable.')),'Download failure is explicitly distinguished from empty downloads');
 check(!partial.button('My purchases').disabled,'Partial failure releases the purchase control');
 partial.state.mode='ok';await partial.submit();check(partial.state.calls.filter(c=>c.name==='chat').every(c=>!JSON.stringify(c.body).includes('synthetic-secret')&&!JSON.stringify(c.body).includes('PRIVATE_ORDER')),'Private partial results never become chat payload');
 const denied=fixture();await denied.open();denied.state.mode='identity';await denied.click('My purchases');
 check(!denied.all().some(n=>n.href?.includes('download_file='))&&denied.button('Reload page'),'One authorization failure discards every parallel account result');
 const malformed=fixture();await malformed.open();malformed.state.mode='malformed';await malformed.click('My purchases');check(malformed.all().some(n=>n.className==='bubble agent'&&n.textContent?.startsWith('PRIVATE_DOWNLOAD'))&&malformed.all().some(n=>n.textContent?.startsWith('Orders are temporarily unavailable.')),'Malformed order envelope does not suppress a valid download response');
 const badAuth=fixture();await badAuth.open();badAuth.state.mode='bad-auth-json';await badAuth.click('My purchases');check(badAuth.button('Reload page')&&!badAuth.all().some(n=>n.href?.includes('download_file=')),'Malformed authentication response still preserves HTTP identity denial');
 const badItems=fixture();await badItems.open();badItems.state.mode='bad-items';await badItems.click('My purchases');check(badItems.all().some(n=>n.className==='bubble agent'&&n.textContent?.startsWith('PRIVATE_DOWNLOAD'))&&badItems.all().some(n=>n.textContent?.startsWith('Orders are temporarily unavailable.')),'Malformed nested order data does not suppress valid downloads or leak parser errors');
 const noWoo=fixture({commerce:false});await noWoo.open();await noWoo.click('My purchases');check(noWoo.button('My purchases').hidden&&!noWoo.state.calls.some(c=>['orders','downloads'].includes(c.name)),'No-Woo widget offers no unavailable purchase action');
 const failed=fixture();await failed.open();failed.state.mode='provider-fail';await failed.submit();
 check(failed.all().some(n=>n.href==='mailto:support@example.test'),'Provider failure offers public support contact');
 check(!failed.state.calls.some(c=>c.name==='handoff')&&!failed.find('composer').children[0].disabled,'Provider failure sends no automatic notification and releases composer');
 failed.state.mode='knowledge-changed';await failed.submit();check(!failed.button('Reload page')&&failed.all().some(n=>n.textContent?.startsWith('Approved information changed. Retry.')),'Source freshness conflict is not falsely called a sign-in failure');
 failed.state.mode='bad-chat';await failed.submit();check(failed.all().some(n=>n.textContent?.startsWith('Support returned an incomplete answer.')),'Malformed chat success is reported as incomplete instead of a blank answer');
 const missing=fixture({support_email:'',handoff_url:''});await missing.open();check(!missing.button('Talk to a person'),'Removed human action is absent');check(!missing.state.calls.some(c=>c.name==='handoff')&&!missing.state.calls.some(c=>c.name==='handoff'),'Missing contact gives honest guidance without pointless notification request');
 const page='https://store.test/customer/files/';
 const account=fixture({downloads_page:page});await account.open();await account.click('My purchases');
 check(account.all().filter(n=>n.tag==='a'&&n.textContent==='My Downloads →').length===1,'One account Downloads action, not a button per file');
 check(account.all().some(n=>n.href===page),'Configured native custom account endpoint preserved');
 check(!account.all().some(n=>n.href?.includes('download_file=')),'Legacy file URL is never rendered as a link');
 account.state.mode='empty-downloads';await account.click('My purchases');check(account.all().filter(n=>n.href===page).length===2,'Account navigation remains available with no current grants');
 account.state.mode='downloads-fail';await account.click('My purchases');check(account.all().filter(n=>n.href===page).length===3,'Download lookup failure preserves safe account navigation with honest error');
 const queryPage=fixture();queryPage.state.accountUrl='https://store.test/?page_id=42&downloads';await queryPage.open();await queryPage.click('My purchases');check(queryPage.all().some(n=>n.href==='https://store.test/?page_id=42&downloads'),'Native plain-permalink account endpoint supported');
 for(const bad of ['https://other.test/files','javascript:alert(1)','https://user:secret@store.test/files','https://store.test/files?download_file=secret','https://store.test/files?order_key=secret','https://store.test/files#secret','http://store.test/files']){const unsafe=fixture({downloads_page:bad});unsafe.state.accountUrl=bad;await unsafe.open();await unsafe.click('My purchases');check(!unsafe.all().some(n=>n.textContent==='My Downloads →'),'Unsafe account destination rejected: '+bad);}
 const guestPage=fixture({loggedIn:false,nonce:'',downloads_page:page});await guestPage.open();await guestPage.click('My purchases');check(guestPage.all().some(n=>n.href===page&&n.textContent==='My Downloads →')&&!guestPage.state.calls.some(c=>['orders','downloads'].includes(c.name)),'Guest opens native account login/navigation without private lookup');
 const identityPage=fixture({downloads_page:page});identityPage.state.mode='identity';await identityPage.open();await identityPage.click('My purchases');check(!identityPage.all().some(n=>n.textContent==='My Downloads →')&&identityPage.button('Reload page'),'Failed identity discards even account UI for this action');
 const guest=fixture({loggedIn:false,nonce:'',login:'https://store.test/login'});await guest.open();await guest.click('My purchases');check(!guest.state.calls.some(c=>['orders','downloads'].includes(c.name))&&guest.all().some(n=>n.textContent==='Sign in →'),'Guest remains on explicit sign-in path without private lookup');
 console.log(`PASS: ${checks} pilot fallback/partial-account/identity/no-send checks (synthetic DOM/fetch).`);
})().catch(e=>{console.error(e);process.exitCode=1;});
