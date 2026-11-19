const assert=require('node:assert/strict');
const {fixture}=require('./widget-catalog.cjs');
let checks=0;const check=(value,label)=>{checks++;assert(value,label);};
(async()=>{
 const sized=fixture({config:{launcher_size:64,launcher_icon_size:28,panel_width:480,panel_height:650,chat_font_size:18,action_font_size:14,composer_height:60,bubble_radius:20,launcher_mobile:'label'}});
 const shell=sized.nodes().find(n=>n.className==='shell right');
 for(const [key,value]of Object.entries({'--launcher-size':'64px','--launcher-icon-size':'28px','--panel-width':'480px','--panel-height':'650px','--chat-font-size':'18px','--action-font-size':'14px','--composer-height':'60px','--bubble-radius':'20px'}))check(shell.style[key]===value||shell.style.values?.[key]===value,'valid sizing reaches isolated surface '+key);
 check(sized.nodes().find(n=>n.className==='launch').attributes['data-mobile-style']==='label','explicit mobile label preserved');
 const input=sized.form().children[0];input.scrollHeight=10;input.listeners.input();check(input.style.height==='60px','configured composer minimum retained');input.scrollHeight=500;input.listeners.input();check(input.style.height==='72px','configured composer remains bounded');
 check(sized.state.calls.length===0,'render/size edits send no requests');
 const bad=fixture({config:{launcher_size:Infinity,panel_width:9999,composer_height:'60px',bubble_radius:-1,launcher_mobile:'<script>'}}),b=bad.nodes().find(n=>n.className==='shell right');
 for(const [key,value]of Object.entries({'--launcher-size':'48px','--panel-width':'360px','--composer-height':'40px','--bubble-radius':'12px'}))check(b.style[key]===value||b.style.values?.[key]===value,'untrusted CSS size falls back '+key);
 check(bad.nodes().find(n=>n.className==='launch').attributes['data-mobile-style']==='icon','invalid mobile style defaults to icon with accessible button name');
 await sized.open();await sized.button('View Catalog').click();check(!sized.button('Ask AI'),'no product shortcut can trigger knowledge/provider calls');check(sized.state.calls.every(x=>['context','browse_catalog'].includes(x.name)),'catalog stays read-only without model/cart actions');
 console.log('PASS: '+checks+' widget size/mobile/removed-shortcut/no-send regression checks.');
})().catch(e=>{console.error(e);process.exitCode=1;});
