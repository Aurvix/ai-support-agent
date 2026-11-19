const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),{webcrypto}=require('node:crypto');
let checks=0;const check=(ok,label)=>{checks++;assert(ok,label);};
class Node {constructor(tag){this.tag=tag;this.children=[];this.listeners={};this.attributes={};this.style={values:{},setProperty(k,v){this.values[k]=v;}};this.hidden=false;this.value='';}append(...items){for(const n of items){n.parent=this;this.children.push(n);}}set textContent(v){this.text=String(v);this.children=[];}get textContent(){return(this.text||'')+this.children.map(x=>x.textContent).join('');}setAttribute(k,v){this.attributes[k]=v;}removeAttribute(k){delete this.attributes[k];}addEventListener(k,v){this.listeners[k]=v;}attachShadow(){return this.shadow=new Node('shadow');}focus(){}remove(){this.parent.children=this.parent.children.filter(x=>x!==this);}getBoundingClientRect(){if(!this.className?.startsWith('shell '))return{width:0,height:0};const open=!this.children[0].hidden,w=open?(env.view.width<480?env.view.width-20:385):100,h=open?Math.min(554,env.view.height-20):44;return{width:w,height:h,left:parseFloat(this.style.values.left)||0,top:parseFloat(this.style.values.top)||0};}}
const all=n=>[n,...n.children.flatMap(all),...(n.shadow?all(n.shadow):[])];let env;
function fixture(config={},width=1000,height=800,bar=0){
 const body=new Node('body'),calls=[],events={},vevents={},view={width,height,offsetLeft:0,offsetTop:0,addEventListener(k,v){vevents[k]=v;}};
 env={view};let observed;
 const window={AISA_CONFIG:{api:'https://fixture.test/api/',stylesheet:'https://fixture.test/widget.css',title:'Support',welcome:'Public reference',position:'right',scope:'synthetic',...config},visualViewport:view,crypto:webcrypto,innerWidth:width,innerHeight:height,addEventListener(k,v){events[k]=v;},setInterval(){},matchMedia:()=>({matches:true}),getComputedStyle:()=>({getPropertyValue:()=>''}),ResizeObserver:class{constructor(cb){observed=cb;}observe(){}}};
 const document={body,createElement:t=>new Node(t),getElementById:id=>id==='wpadminbar'&&bar?{getBoundingClientRect:()=>({top:0,bottom:bar})}:null,addEventListener(){}};
 vm.runInNewContext(fs.readFileSync('ai-support-agent/assets/widget.js','utf8'),{window,document,location:{origin:'https://fixture.test'},fetch:async url=>{calls.push(url);return{ok:true,status:200,json:async()=>({memory_enabled:false,customer_tickets:false,archive_enabled:false})};},URL,AbortController,setTimeout,clearTimeout});
 const nodes=all(body),shell=nodes.find(n=>n.className?.startsWith('shell '));return{window,view,events,vevents,calls,shell,nodes,observed};
}
function bounds(f,label){const b=f.shell.getBoundingClientRect();check(b.left>=10&&b.top>=10&&b.left+b.width<=f.view.width-10&&b.top+b.height<=f.view.height-10,label);}
(async()=>{
 for(const position of ['left','right','top-left','top-center','top-right','center-left','center','center-right','bottom-center']){
  const f=fixture({position,widget_offset_x:24,widget_offset_y:80});bounds(f,'closed '+position);const y=f.shell.style.values.top;
  if(position==='left'||position==='right'||position==='bottom-center')check(parseFloat(y)===676,'bottom launcher raised80px above bottom edge');
  await f.shell.children[1].listeners.click();f.observed();bounds(f,'expanded '+position);check(f.shell.children[0].hidden===false,'opening preserves content '+position);
  f.nodes.find(n=>n.className==='close').listeners.click();bounds(f,'closed again '+position);check(f.shell.style.values.top===y,'close restores requested anchor '+position);check(f.calls.length===1&&f.calls[0].endsWith('context'),'position/open/close creates only explicit context check');
 }
 for(const value of [-2000,2000])for(const position of ['top-left','top-right','center','left','right']){
  const f=fixture({position,widget_offset_x:value,widget_offset_y:value},320,568);bounds(f,'mobile extreme closed');await f.shell.children[1].listeners.click();f.observed();bounds(f,'mobile extreme expanded');
  f.view.width=390;f.view.height=760;f.vevents.resize();bounds(f,'resize stays visible');
 }
 for(const position of ['__proto__','constructor','<script>']){const f=fixture({position,widget_offset_x:'2000px',widget_offset_y:Infinity});check(f.shell.attributes['data-aisa-horizontal']==='right'&&f.shell.attributes['data-aisa-vertical']==='bottom','invalid anchor cannot select inherited keys');bounds(f,'untrusted values fallback');check(f.calls.length===0,'no sends from invalid presentation settings');}
 const bar=fixture({position:'top-left',widget_offset_y:0},1000,800,32);check(parseFloat(bar.shell.style.values.top)===42,'normal WordPress admin bar remains accessible');
 const css=fs.readFileSync('ai-support-agent/assets/widget.css','utf8');check(css.includes('z-index:2147483000;pointer-events:none')&&css.includes('.shell{pointer-events:auto'),'isolated host layer covers normal menus without blocking page outside widget');
 console.log('PASS: '+checks+' positioning/viewport/legacy/offset/resize/admin-bar/no-send checks (synthetic geometry).');
})().catch(e=>{console.error(e);process.exitCode=1;});
