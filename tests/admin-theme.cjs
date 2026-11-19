// Server-owned plugin appearance; old browser preferences must not override it.
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');let checks=0;
for(const theme of ['light','dark']){
 let reads=0,writes=0,calls=0;const desk={dataset:{theme},querySelector:()=>null};const root={dataset:{theme},querySelector:s=>s==='[data-aisa-inbox]'?desk:null,addEventListener(){}};
 const window={localStorage:{getItem(){reads++;return theme==='light'?'dark':'light';},setItem(){writes++;}},addEventListener(){},setInterval(){}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../ai-support-agent/assets/admin.js'),'utf8'),{document:{querySelector:()=>root},window,fetch(){calls++;},location:{origin:'https://fixture.test'},URL,AbortController,setTimeout,clearTimeout});
 assert.equal(root.dataset.theme,theme);assert.equal(desk.dataset.theme,theme);checks++;assert.equal(reads+writes,0);checks++;assert.equal(calls,0);checks++;
}
console.log('PASS: '+checks+' server-owned theme/no-legacy-browser-override/no-settings-or-message-send checks.');
