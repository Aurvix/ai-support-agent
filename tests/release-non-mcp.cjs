// Exact review archive, disposable WordPress/Woo only; no client credentials/network adapters.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),version=process.argv[2];
if(!/^\d+\.\d+\.\d+$/.test(version||''))throw Error('An exact review version is required.');
const sha=b=>crypto.createHash('sha256').update(b).digest('hex').toUpperCase();
const manifest=JSON.parse(fs.readFileSync(path.join(root,'outputs/package-'+version+'.json'),'utf8'));
if(sha(fs.readFileSync(path.join(root,'dist/ai-support-agent-'+version+'.zip')))!==manifest.sha256)throw Error('Archive changed.');
for(const file of manifest.files)if(sha(fs.readFileSync(path.join(root,file.path)))!==file.sha256)throw Error('Source differs from immutable archive.');
const report={version,status:'RUNNING',archive_sha256:manifest.sha256,exact_zip:true,mcp_deferred:true,actual_external_calls:0,real_login_verified:false,external_delivery_verified:false,production_ready:false,results:[]};
const output=path.join(root,'outputs/release-production-'+version+'-local.json');
const save=()=>fs.writeFileSync(output,JSON.stringify(report,null,2)+'\n');save();
const profiles=[['main','--without-mcp'],['wire','--wire-check'],['notifications','--notification-check'],['diagnostics','--diagnostic-check'],['multisite','--multisite-check'],['live-inbox','--live-inbox-check'],['conversation','--conversation-inbox-check'],['public','--public-reference-check'],['customization','--customization-check'],['catalog','--catalog-check'],['admin','--admin-training-check'],['pilot','--pilot-check'],['upgrade','--upgrade-check']];
for(const [profile,flag] of profiles)for(const mode of ['hpos','cpt']){
 const args=['tests/wp-integration.mjs',flag,'--without-mcp','--zip='+version,...(mode==='cpt'?['--cpt']:[])];
 const started=Date.now(),r=spawnSync(process.execPath,args,{cwd:root,encoding:'utf8',timeout:180000,maxBuffer:2*1024*1024,windowsHide:true});
 const passed=!r.error&&r.status===0;
 report.results.push({profile,mode,status:passed?'PASS':'FAIL',elapsed_ms:Date.now()-started,summary:(r.stdout||'').trim().split('\n').at(-1),...(passed?{}:{diagnostic:(r.stderr||r.error?.message||'Unsuccessful test exit').slice(-2400)})});
 save();console.log(profile+' '+mode+': '+(passed?'PASS':'FAIL'));
}
report.status=report.results.every(r=>r.status==='PASS')?'PASS':'FAIL';report.completed_profiles=report.results.length;save();
if(report.status!=='PASS')process.exitCode=1;
