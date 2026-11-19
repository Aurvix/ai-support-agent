const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const {fixture}=require('./widget-catalog.cjs');let checks=0;const check=(x,label)=>{checks++;assert(x,label);};
for(const theme of ['classic','messenger','ocean','slate','forest','indigo','rose','amber','teal','plum'])for(const mode of ['light','dark']){
 const f=fixture({config:{ui_theme:theme,admin_theme:mode,color:'#c020a0',header_color:'#fdf0ff',theme_palette:{'--ui-accent':'#176b53','--ui-panel':'#ffffff'}}}),shell=f.nodes().find(n=>n.className?.startsWith('shell '));
 check(shell.attributes['data-ui-theme']===theme&&shell.attributes['data-theme']===mode,'same server theme/appearance reaches widget: '+theme+'/'+mode);
 check(theme!=='classic'||shell.style['--accent']==='#c020a0','Original preserves configured accent');
 check(theme==='classic'||shell.style['--accent']!=='#c020a0','preset palette applied without editing configured branding');
 check(['#000000','#ffffff'].includes(shell.style['--accent-ink']),'foreground chosen from actual theme accent');
 check(f.state.calls.length===0,'theme does not make model/account requests');
}
const unsafe=fixture({config:{ui_theme:'rose',theme_palette:{'--ui-accent':'url(https://unsafe.invalid)','--ui-panel':'#ffffff','--not-allowed':'#ffffff'}}}),unsafeShell=unsafe.nodes().find(n=>n.className?.startsWith('shell '));check(!unsafeShell.style['--not-allowed']&&unsafeShell.style['--ui-accent']===undefined,'public palette rejects arbitrary properties and non-color CSS');
const invalid=fixture({config:{ui_theme:'<script>bad</script>',admin_theme:'unknown'}}),fallback=invalid.nodes().find(n=>n.className?.startsWith('shell '));check(fallback.attributes['data-ui-theme']==='classic'&&fallback.attributes['data-theme']==='light','invalid public config fails safely to Original/Light');
const defaults=fixture().nodes().find(n=>n.className?.startsWith('shell '));check(defaults.attributes['data-ui-theme']==='classic','legacy page without theme uses Original');
const choice={value:'classic',listeners:{},addEventListener(k,f){this.listeners[k]=f;}},appearance={value:'light',listeners:{},addEventListener(k,f){this.listeners[k]=f;}},preview={attrs:{},setAttribute(k,v){this.attrs[k]=v;}},calls=[];
const root={dataset:{theme:'light'},querySelector:s=>({'[name="ui_theme"]':choice,'[name="admin_theme"]':appearance,'[data-aisa-theme-preview]':preview}[s]||null),addEventListener(){}};
vm.runInNewContext(fs.readFileSync('ai-support-agent/assets/admin.js','utf8'),{document:{querySelector:()=>root},window:{addEventListener(){},setInterval(){}},fetch(){calls.push('network');},location:{origin:'https://fixture.test'},URL,AbortController,setTimeout,clearTimeout});
choice.value='messenger';choice.listeners.change();check(preview.attrs['data-ui-theme']==='messenger'&&root.dataset.theme==='light','preview changes only preview, not applied admin theme');
appearance.value='dark';appearance.listeners.change();check(preview.attrs['data-theme']==='dark','appearance preview follows choice');
choice.value='javascript';choice.listeners.change();check(preview.attrs['data-ui-theme']==='classic','preview rejects arbitrary values');check(calls.length===0,'preview never saves or calls provider');
console.log('PASS: '+checks+' shared theme/default/branding/fallback/preview-no-autosave checks (synthetic DOM).');
