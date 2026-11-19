const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');let checks=0;const check=(ok,label)=>{checks++;assert(ok,label);};
function fixture(mode='ok'){
 const state={calls:[],mode,hidden:false},note={textContent:''},badge={textContent:'AI active'},revision={value:'2'},draft={value:'Unsent administrator reply'},handlers={},events={};
 const operation={value:'human_reply'},form={querySelector:()=>operation};
 const row={dataset:{chatRevision:'2'},querySelector(s){return s==='[data-chat-status]'?badge:{form:{querySelector:()=>revision}};}};
 const desk={dataset:{api:mode==='foreign'?'https://untrusted.invalid/status':'https://store.test/wp-json/aisa/v1/inbox_status',nonce:'fixture-nonce'},querySelector(s){return s==='[data-aisa-inbox-updates]'?note:s==='[data-chat-id="7"]'?row:null;}};
 const root={querySelector(s){return s==='[data-aisa-inbox]'?desk:s==='[data-aisa-inbox-updates]'?note:null;},addEventListener(k,f){(handlers[k]??=[]).push(f);}};
 const window={addEventListener(k,f){(events[k]??=[]).push(f);},setInterval(f){state.poll=f;}};
 const fetch=async(url,o)=>{state.calls.push({url,o});if(state.mode==='pending')await new Promise(r=>state.release=r);if(state.mode==='fail')throw Error('Synthetic failure');return{ok:state.mode!=='forbidden',json:async()=>({chats:state.mode==='many'?Array.from({length:101},()=>({id:7,revision:3,mode:'human',pending:false})): [{id:7,revision:3,mode:state.mode==='unsafe'?'<img>':'human',pending:state.mode==='typing'}]})};};
 const document={querySelector:()=>root,get hidden(){return state.hidden;}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../ai-support-agent/assets/admin.js'),'utf8'),{document,window,fetch,location:{origin:'https://store.test'},URL,AbortController,setTimeout,clearTimeout});
 return{state,note,badge,revision,draft,handlers,events,form};
}
(async()=>{
 const f=fixture();check(!f.state.calls.length,'render never submits a message or model request');await f.state.poll();check(f.state.calls.length===1&&f.state.calls[0].o.headers['X-WP-Nonce']==='fixture-nonce'&&f.state.calls[0].o.credentials==='same-origin','status uses existing same-origin authenticated nonce transport');check(f.badge.textContent==='Support agent active'&&f.revision.value==='3','status badge and takeover revision follow latest server observation');check(f.draft.value==='Unsent administrator reply'&&f.note.textContent.includes('Refresh Inbox'),'poll preserves unsent reply and requests transcript refresh');check(f.state.calls.every(c=>c.url.endsWith('/inbox_status')&&c.o.body==='{}'),'poll never sends transcript, reply, provider command or settings patch');
 for(const fn of f.handlers.input)fn({target:{form:f.form}});let warned=false;for(const fn of f.events.beforeunload)fn({preventDefault(){warned=true;}});check(warned,'navigation warns about unsent admin reply');for(const fn of f.handlers.submit)fn({target:f.form});warned=false;for(const fn of f.events.beforeunload)fn({preventDefault(){warned=true;}});check(!warned,'explicit reply submit is not blocked by dirty warning');
 const typing=fixture('typing');await typing.state.poll();check(typing.badge.textContent==='AI preparing reply','AI pending state is visible to administrator');
 for(const mode of ['foreign','fail','forbidden','many','unsafe']){const bad=fixture(mode);await bad.state.poll();check(bad.note.textContent.includes('unavailable')&&bad.badge.textContent==='AI active','honest fail-closed status: '+mode);if(mode==='foreign')check(!bad.state.calls.length,'status URL cannot send admin nonce to another origin');}
 const hidden=fixture();hidden.state.hidden=true;await hidden.state.poll();check(!hidden.state.calls.length,'hidden admin page does not poll');const busy=fixture('pending');const pending=busy.state.poll();await busy.state.poll();check(busy.state.calls.length===1,'overlapping status polls are suppressed');busy.state.release();await pending;
 console.log('PASS: '+checks+' admin live-status/nonce/typed-draft/failure/concurrency/no-send checks (synthetic transport).');
})().catch(e=>{console.error(e);process.exitCode=1;});
