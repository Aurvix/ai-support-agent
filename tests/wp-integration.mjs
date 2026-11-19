import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP, loadPHPRuntime } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/node-8-1';
import { createHash } from 'node:crypto';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const storage = process.argv.includes('--cpt') ? 'cpt' : 'hpos';
const archiveVersion = process.argv.find(arg => /^--zip=\d+\.\d+\.\d+$/.test(arg))?.slice(6);
const versionAtLeast=(actual,minimum)=>{const a=actual.split('.').map(Number),b=minimum.split('.').map(Number),i=a.findIndex((part,index)=>part!==b[index]);return i<0||a[i]>b[i];};
const php = new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
try {
  const probe = await php.run({ code: '<?php echo json_encode(["PHP"=>PHP_VERSION,"zip"=>class_exists("ZipArchive"),"sqlite"=>extension_loaded("pdo_sqlite"),"mysqli"=>extension_loaded("mysqli")]);' });
  console.log(probe.text);
  const packages = JSON.parse(fs.readFileSync(path.join(root, 'tests/runtime-packages.json'), 'utf8'));
  php.mkdir('/packages');
  for (const [name, spec] of Object.entries(packages)) {
    const file = path.join(root, 'tests/runtime-cache', spec.file);
    if (!fs.existsSync(file)) throw new Error('Missing local package: ' + spec.file + '. Download the pinned official archive described in tests/runtime-packages.json.');
    const data = fs.readFileSync(file);
    if (createHash('sha256').update(data).digest('hex').toUpperCase() !== spec.sha256) throw new Error('Package hash mismatch: ' + name);
    php.writeFile('/packages/' + name + '.zip', data);
  }
  const extraction = await php.run({ code: `<?php
    ini_set('memory_limit','1024M');
    foreach (['wordpress'=>'/','woocommerce'=>'/wordpress/wp-content/plugins','sqlite'=>'/wordpress/wp-content/plugins'] as $name=>$target) {
      if (!is_dir($target)) mkdir($target,0777,true);
      $zip=new ZipArchive(); if ($zip->open('/packages/'.$name.'.zip')!==true || !$zip->extractTo($target)) throw new RuntimeException('Archive extraction failed: '.$name); $zip->close();
    }
    copy('/wordpress/wp-content/plugins/sqlite-database-integration/db.copy','/wordpress/wp-content/db.php');
    mkdir('/wordpress/wp-content/mu-plugins',0777,true);
    echo 'Extracted official archives into disposable memory.\n';` });
  console.log(extraction.text);
  const sourceHashes = {};
  const copyTree = (local, remote) => {
    php.mkdir(remote);
    for (const entry of fs.readdirSync(local, { withFileTypes: true })) {
      if (entry.isDirectory()) copyTree(path.join(local, entry.name), remote + '/' + entry.name);
      else {
        const bytes = fs.readFileSync(path.join(local, entry.name));
        php.writeFile(remote + '/' + entry.name, bytes);
        sourceHashes[(remote + '/' + entry.name).replace('/wordpress/wp-content/plugins/', '')] = createHash('sha256').update(bytes).digest('hex').toUpperCase();
      }
    }
  };
  if (!archiveVersion) copyTree(path.join(root, 'ai-support-agent'), '/wordpress/wp-content/plugins/ai-support-agent');
  if (archiveVersion) {
    // Re-check an immutable review archive without modifying the local source tree.
    php.writeFile('/packages/review.zip', fs.readFileSync(path.join(root, `dist/ai-support-agent-${archiveVersion}.zip`)));
    const archive = await php.run({ code: `<?php
      $zip=new ZipArchive(); if ($zip->open('/packages/review.zip')!==true) throw new RuntimeException('Review archive unavailable');
      $hashes=[]; for ($i=0;$i<$zip->numFiles;$i++) { $entry=$zip->getNameIndex($i); if (str_ends_with($entry,'/')) continue;
        if (!str_starts_with($entry,'ai-support-agent/') || str_contains($entry,'..')) throw new RuntimeException('Invalid review entry');
        $bytes=$zip->getFromIndex($i); $target='/wordpress/wp-content/plugins/'.$entry; if (!is_dir(dirname($target))) mkdir(dirname($target),0777,true);
        file_put_contents($target,$bytes); $hashes[$entry]=strtoupper(hash('sha256',$bytes)); }
      $zip->close(); echo json_encode($hashes);` });
    if (archive.errors) throw new Error(archive.errors);
    for (const key of Object.keys(sourceHashes)) delete sourceHashes[key];
    Object.assign(sourceHashes, JSON.parse(archive.text));
  }
  php.writeFile('/wordpress/wp-config.php', `<?php
    define('DB_NAME','isolated'); define('DB_USER',''); define('DB_PASSWORD',''); define('DB_HOST','localhost');
    define('DB_ENGINE','sqlite'); define('DB_DIR','/wordpress/wp-content/database/'); define('DB_FILE','isolated.sqlite');
    define('WP_HOME','https://aisa.local.test'); define('WP_SITEURL','https://aisa.local.test');
    define('WP_DEBUG',false); define('WP_DEBUG_LOG',false); define('WP_HTTP_BLOCK_EXTERNAL',true);
    define('DISABLE_WP_CRON',true); define('DISALLOW_FILE_MODS',true); define('AUTOMATIC_UPDATER_DISABLED',true);
    define('WP_MEMORY_LIMIT','512M'); define('WP_MAX_MEMORY_LIMIT','1024M');
    foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $name) define($name,'public-fixture-only-not-a-credential-'.$name);
    $table_prefix='wp_'; if (!defined('ABSPATH')) define('ABSPATH','/wordpress/'); require_once ABSPATH.'wp-settings.php';`);
  php.writeFile('/wordpress/wp-content/mu-plugins/isolated-tests.php', `<?php
    add_filter('pre_http_request',function(){ return new WP_Error('isolated_network_disabled','All external HTTP is disabled in this disposable fixture.'); },PHP_INT_MAX);
    add_filter('pre_wp_mail',function(){ return false; },PHP_INT_MAX);
    add_filter('woocommerce_email_enabled_customer_completed_order','__return_false');
    add_filter('woocommerce_email_enabled_customer_processing_order','__return_false');
    add_filter('woocommerce_email_enabled_new_order','__return_false');`);
  let candidateFiles;
  if (process.argv.includes('--upgrade-check')) {
    candidateFiles=Object.fromEntries(Object.keys(sourceHashes).map(entry=>[entry,php.readFileAsBuffer('/wordpress/wp-content/plugins/'+entry)]));
    const baseline=fs.readFileSync(path.join(root,'dist/ai-support-agent-0.1.7.zip'));
    if (createHash('sha256').update(baseline).digest('hex').toUpperCase()!==fs.readFileSync(path.join(root,'outputs/legacy-fixture-sha256.txt'),'utf8').trim()) throw new Error('Upgrade baseline hash mismatch');
    php.writeFile('/packages/upgrade-baseline.zip',baseline);
    const overlay=await php.run({code:`<?php $z=new ZipArchive(); if ($z->open('/packages/upgrade-baseline.zip')!==true || !$z->extractTo('/wordpress/wp-content/plugins')) throw new RuntimeException('Upgrade baseline overlay failed'); $z->close();`});
    if (overlay.errors) throw new Error(overlay.errors);
  }
  php.writeFile('/wordpress/setup.php', fs.readFileSync(path.join(root, 'tests/wp-bootstrap.php')));
  const bootstrap = await php.run({ scriptPath: '/wordpress/setup.php', protocol: 'https', $_SERVER: { HTTP_HOST: 'aisa.local.test', HTTPS: 'on', SERVER_PORT: '443' } });
  console.log(bootstrap.text);
  if (bootstrap.errors) throw new Error(bootstrap.errors);
  const storageSetup = await php.run({ code: `<?php require '/wordpress/wp-load.php'; update_option('woocommerce_custom_orders_table_enabled','${storage === 'hpos' ? 'yes' : 'no'}'); update_option('woocommerce_custom_orders_table_data_sync_enabled','no');`, protocol: 'https', $_SERVER: { HTTP_HOST: 'aisa.local.test', HTTPS: 'on' } });
  if (storageSetup.errors || storageSetup.text) throw new Error(storageSetup.errors || storageSetup.text);
  if(process.argv.includes('--mcp-review-check')){
    php.writeFile('/wordpress/mcp-review.php',fs.readFileSync(path.join(root,'tests/wp-mcp-review.php')));
    const out=await php.run({scriptPath:'/wordpress/mcp-review.php',protocol:'https',$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});if(out.errors)throw new Error(out.errors);let report;try{report=JSON.parse(out.text);}catch{throw new Error(out.text);}if(report.status!=='PASS')throw Error('MCP review failed');report.storage=storage;report.plugin_sha256=sourceHashes;fs.writeFileSync(path.join(root,'outputs/mcp-review-'+report.version+'-'+storage+'.json'),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,checks:report.checks,storage,real_client_verified:false}));
  }else if(process.argv.includes('--live-inbox-check')){
    php.writeFile('/wordpress/live-inbox.php',fs.readFileSync(path.join(root,'tests/wp-live-inbox.php')));
    const out=await php.run({scriptPath:'/wordpress/live-inbox.php',protocol:'https',$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});if(out.errors)throw Error(out.errors);let report;try{report=JSON.parse(out.text);}catch{throw Error(out.text);}if(report.status!=='PASS')throw Error('Live inbox test failed');report.storage=storage;report.plugin_sha256=sourceHashes;fs.writeFileSync(path.join(root,'outputs/live-inbox-'+report.version+'-'+storage+'.json'),JSON.stringify(report,null,2));fs.writeFileSync(path.join(root,'outputs/live-inbox-'+report.version+'.html'),php.readFileAsText('/wordpress/live-inbox.html'));for(const [view,theme] of [['settings','light'],['settings','dark'],['providers','dark'],['training','dark'],['widget','dark'],['inbox','dark']])fs.writeFileSync(path.join(root,`outputs/plugin-${view}-${theme}-${report.version}.html`),php.readFileAsText(`/wordpress/plugin-${view}-${theme}.html`));console.log(JSON.stringify({status:report.status,checks:report.checks,storage}));
  }else if(process.argv.includes('--conversation-inbox-check')){
    php.writeFile('/wordpress/conversation-inbox.php',fs.readFileSync(path.join(root,'tests/wp-conversation-inbox.php')));
    const out=await php.run({scriptPath:'/wordpress/conversation-inbox.php',protocol:'https',$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});if(out.errors)throw Error(out.errors);let report;try{report=JSON.parse(out.text);}catch{throw Error(out.text);}if(report.status!=='PASS')throw Error('Conversation inbox test failed');report.storage=storage;report.plugin_sha256=sourceHashes;fs.writeFileSync(path.join(root,'outputs/conversation-inbox-'+report.version+'-'+storage+'.json'),JSON.stringify(report,null,2));for(const view of ['inbox','assistant'])fs.writeFileSync(path.join(root,'outputs/conversation-'+report.version+'-'+view+'.html'),php.readFileAsText('/wordpress/conversation-'+view+'.html'));console.log(JSON.stringify({status:report.status,checks:report.checks,storage}));
  }else if(process.argv.includes('--backup-restore-check')){
    php.writeFile('/wordpress/backup-restore.php',fs.readFileSync(path.join(root,'tests/wp-backup-restore.php')));
    const phases=[],snapshot=new Map();const digest=bytes=>createHash('sha256').update(bytes).digest('hex');
    const step=async phase=>{const out=await php.run({scriptPath:'/wordpress/backup-restore.php',protocol:'https',env:{AISA_BACKUP_PHASE:phase},$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});if(out.errors)throw Error(out.errors);let report;try{report=JSON.parse(out.text);}catch{throw Error(out.text);}if(report.status!=='PASS')throw Error('Recovery phase failed');phases.push(report);};
    await step('seed');
    const files=['/wordpress/wp-content/database/isolated.sqlite','/wordpress/wp-content/uploads/woocommerce_uploads/restore-fixture.txt',...Object.keys(sourceHashes).map(entry=>'/wordpress/wp-content/plugins/'+entry)];
    for(const file of files){const bytes=Buffer.from(php.readFileAsBuffer(file));snapshot.set(file,{bytes,hash:digest(bytes)});}
    const validate=entries=>{for(const [file,item]of entries)if(digest(item.bytes)!==item.hash)throw Error('Recovery checksum mismatch');};validate(snapshot);
    const bad=new Map(snapshot);const first=files[0],original=snapshot.get(first),altered=Buffer.from(original.bytes);altered[0]^=1;bad.set(first,{bytes:altered,hash:original.hash});let corruptedBackupRejected=false;try{validate(bad);}catch{corruptedBackupRejected=true;}if(!corruptedBackupRejected)throw Error('Corrupted backup guard failed');
    await step('mutate');
    if(digest(php.readFileAsBuffer(first))===original.hash)throw Error('Database mutation was not observed');
    const css='/wordpress/wp-content/plugins/ai-support-agent/assets/widget.css';php.writeFile(css,'/* synthetic corruption for restore test */');
    validate(snapshot);for(const[file,item]of snapshot)php.writeFile(file,item.bytes);
    for(const[file,item]of snapshot)if(digest(php.readFileAsBuffer(file))!==item.hash)throw Error('Restored bytes mismatch');
    await step('verify');
    const report={status:'PASS',version:phases[0].version,storage,checks:phases.reduce((n,p)=>n+p.checks,0)+4,phases,corrupted_backup_rejected:corruptedBackupRejected,restored_files:files.length,plugin_sha256:sourceHashes,scope:'Disposable memory SQLite database, one synthetic upload and exact plugin files; not a hosting backup/restore certification',credentials_created:false,real_login_verified:false,external_calls:0};
    fs.writeFileSync(path.join(root,'outputs/backup-restore-'+report.version+'-'+storage+'.json'),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,checks:report.checks,storage}));
  }else if(process.argv.includes('--nvidia-ab-compat-check')){
    php.writeFile('/wordpress/nvidia-support-ab.json',fs.readFileSync(path.join(root,'tests/nvidia-support-ab.json')));
    php.writeFile('/wordpress/nvidia-ab-compat.php',fs.readFileSync(path.join(root,'tests/wp-nvidia-ab-compat.php')));
    const step=await php.run({scriptPath:'/wordpress/nvidia-ab-compat.php',protocol:'https',$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});
    if(step.errors)throw new Error(step.errors);const report=JSON.parse(step.text);if(report.status!=='PASS')throw new Error('NVIDIA fixture compatibility failed');report.storage=storage;report.plugin_sha256=sourceHashes;fs.writeFileSync(path.join(root,'outputs/nvidia-ab-compat-'+report.version+'-'+storage+'.json'),JSON.stringify(report,null,2));
  }else if(process.argv.includes('--public-reference-check')){
    php.writeFile('/wordpress/catalog-auto-check.php',fs.readFileSync(path.join(root,'tests/wp-catalog-auto-check.php')));
    php.writeFile('/wordpress/public-reference.php',fs.readFileSync(path.join(root,'tests/wp-public-reference.php')));
    const step=await php.run({scriptPath:'/wordpress/public-reference.php',protocol:'https',$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});
    if(step.errors)throw new Error(step.errors);const report=JSON.parse(step.text);if(report.status!=='PASS')throw new Error('Public reference contract failed');report.storage=storage;report.plugin_sha256=sourceHashes;fs.writeFileSync(path.join(root,'outputs/public-reference-'+report.version+'-'+storage+'.json'),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,checks:report.checks,storage}));
  }else if(process.argv.includes('--customization-check')){
    php.writeFile('/wordpress/experience-check.php',fs.readFileSync(path.join(root,'tests/wp-experience-check.php')));php.writeFile('/wordpress/theme-check.php',fs.readFileSync(path.join(root,'tests/wp-theme-check.php')));php.writeFile('/wordpress/customization-sync.php',fs.readFileSync(path.join(root,'tests/wp-customization-sync.php')));
    const step=await php.run({scriptPath:'/wordpress/customization-sync.php',protocol:'https',$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});
    if(step.errors)throw new Error(step.errors);const report=JSON.parse(step.text);if(report.status!=='PASS')throw new Error('Customization contract failed');report.storage=storage;report.plugin_sha256=sourceHashes;fs.writeFileSync(path.join(root,'outputs/customization-'+report.version+'-'+storage+'.json'),JSON.stringify(report,null,2));fs.writeFileSync(path.join(root,'outputs/theme-palettes-'+report.version+'.json'),php.readFileAsText('/wordpress/theme-palettes.json'));for(const theme of ['classic','messenger','ocean','slate','forest','indigo','rose','amber','teal','plum'])for(const mode of ['light','dark']){fs.writeFileSync(path.join(root,'outputs/theme-'+report.version+'-'+theme+'-'+mode+'.html'),php.readFileAsText('/wordpress/theme-'+theme+'-'+mode+'.html'));for(const view of ['overview','providers','widget','training','woocommerce','notifications','mcp','diagnostics','inbox','assistant','settings','handoff'])fs.writeFileSync(path.join(root,'outputs/theme-'+report.version+'-'+theme+'-'+mode+'-'+view+'.html'),php.readFileAsText('/wordpress/theme-'+theme+'-'+mode+'-'+view+'.html'));}for(const view of ['widget','training'])fs.writeFileSync(path.join(root,'outputs/customization-'+report.version+'-'+view+'.html'),php.readFileAsText('/wordpress/customization-'+view+'.html'));console.log(JSON.stringify({status:report.status,checks:report.checks,storage}));
  }else if(process.argv.includes('--catalog-check')){
    php.writeFile('/wordpress/catalog-check.php',fs.readFileSync(path.join(root,'tests/wp-catalog.php')));
    php.writeFile('/wordpress/catalog-commerce.php',fs.readFileSync(path.join(root,'tests/wp-catalog-commerce.php')));
    const step=await php.run({scriptPath:'/wordpress/catalog-check.php',protocol:'https',$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});
    if(step.errors)throw new Error(step.errors);const report=JSON.parse(step.text);if(report.status!=='PASS')throw new Error('Catalog contract failed');report.storage=storage;report.plugin_sha256=sourceHashes;fs.writeFileSync(path.join(root,'outputs/catalog-'+report.version+'-'+storage+'.json'),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,checks:report.checks,storage}));
  }else if(process.argv.includes('--admin-training-check')){
    php.writeFile('/wordpress/admin-training.php',fs.readFileSync(path.join(root,'tests/wp-admin-training.php')));
    php.writeFile('/wordpress/provider-choice-tests.php',fs.readFileSync(path.join(root,'tests/provider-choice-tests.php')));
    const results=[];
    for(const phase of ['core','upload-mixed','upload-failures','upload-storage-fail','upload-attribution-fail']){
      const boundary='aisa-admin-training-boundary';
      const files=phase==='upload-storage-fail'?[['fail.md','Public fact that fails storage.'],['retained.md','Retained public fact.']]:phase==='upload-attribution-fail'?[['attribution.md','Public unattributed fact.']]:phase==='upload-mixed'?[['policy-one.md','Policy one public fact.'],['policy-two.txt','Policy two public fact.'],['reject.pdf','not supported']]:[['empty.txt','   '],['binary.txt','a\0b'],['oversize.md','x'.repeat(262145)],['valid.md','Valid public fact.']];
      const body=files.map(([name,content])=>'--'+boundary+'\r\nContent-Disposition: form-data; name="knowledge_files[]"; filename="'+name+'"\r\nContent-Type: text/plain\r\n\r\n'+content+'\r\n').join('')+'--'+boundary+'--\r\n';
      const result=await php.run({scriptPath:'/wordpress/admin-training.php',method:'POST',headers:{'Content-Type':'multipart/form-data; boundary='+boundary},body:Buffer.from(body),protocol:'https',env:{AISA_ADMIN_TRAINING_PHASE:phase},$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443'}});
      if(result.errors)throw new Error(result.errors);let value;try{value=JSON.parse(result.text);}catch{throw new Error(result.text);}if(value.status!=='PASS')throw new Error(result.text);results.push(value);
    }
    const report={status:'PASS',version:results[0].version,storage,checks:results.reduce((n,r)=>n+r.checks,0),phases:results,plugin_sha256:sourceHashes,external_calls:0,real_login_verified:false,mcp_testing:'deferred'};
    fs.writeFileSync(path.join(root,'outputs/admin-training-'+report.version+'-'+storage+'.json'),JSON.stringify(report,null,2));
    for(const view of ['overview','providers','training','widget','diagnostics'])fs.writeFileSync(path.join(root,'outputs/admin-'+report.version+'-'+view+'.html'),php.readFileAsText('/wordpress/admin-'+report.version+'-'+view+'.html'));
    console.log(JSON.stringify({status:'PASS',checks:report.checks,storage}));
  }else if(process.argv.includes('--pilot-check')){
    php.writeFile('/wordpress/pilot.php',fs.readFileSync(path.join(root,'tests/wp-pilot.php')));const phases=[];
    for(const phase of ['first-use','no-woo']){
      const step=await php.run({scriptPath:'/wordpress/pilot.php',protocol:'https',env:{AISA_PILOT_PHASE:phase,AISA_PILOT_STORAGE:storage},$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});
      if(step.errors)throw new Error(step.errors);const result=JSON.parse(step.text);if(result.status!=='PASS')throw new Error('Pilot phase failed');phases.push(result);
    }
    const version=phases[0].plugin_version,report={status:'PASS',profile:'limited-pilot-local',plugin_version:version,storage,checks:phases.reduce((sum,p)=>sum+p.checks,0),phases,plugin_sha256:sourceHashes,mcp_testing:'deferred',real_login_verified:false,external_integration_verified:false};
    fs.writeFileSync(path.join(root,`outputs/pilot-${version}-${storage}.json`),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,checks:report.checks,storage}));
  }else if(process.argv.includes('--knowledge-check')||process.argv.includes('--knowledge-probe')){
    php.writeFile('/wordpress/knowledge-quality.php',fs.readFileSync(path.join(root,'tests/wp-knowledge-quality.php')));
    const probe=process.argv.includes('--knowledge-probe'),cases=[];
    const version=/Version:\s*(\d+\.\d+\.\d+)/.exec(php.readFileAsText('/wordpress/wp-content/plugins/ai-support-agent/ai-support-agent.php'))?.[1];
    const reportPath=path.join(root,`outputs/knowledge-${probe?'baseline':'quality'}-${version}-${storage}.json`);
    const save=status=>fs.writeFileSync(reportPath,JSON.stringify({status,plugin_version:version,storage,checks:cases.reduce((sum,item)=>sum+item.checks,0),cases,plugin_sha256:sourceHashes,real_login_verified:false,credentials_created:false,external_sends:0,harness_sha256:Object.fromEntries(['tests/wp-knowledge-quality.php','tests/wp-integration.mjs'].map(file=>[file,createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex').toUpperCase()]))},null,2));
    save('RUNNING');
    try{for(const name of ['upload-ok','upload-save-fail','upload-empty','upload-binary','upload-utf8','upload-oversize','upload-size-mismatch','upload-extension','approve-meta-fail','revoke-meta-fail','approve-draft-fail','approve-idempotent','remove-fail','reject-fail','remove-ok','reject-ok','non-admin','bad-nonce']){
      const boundary='aisa-isolated-knowledge-boundary';
      const content=name==='upload-empty'?'   \n':name==='upload-binary'?'binary\0text':name==='upload-utf8'?Buffer.from([0xff,0xfe]):['upload-oversize','upload-size-mismatch'].includes(name)?'x'.repeat(262145):'Ingestquartz path C:\\support\\policy\nनमस्ते public help.';
      const body=Buffer.concat([Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="knowledge"; filename="synthetic.${name==='upload-extension'?'php':'md'}"\r\nContent-Type: text/plain\r\n\r\n`),Buffer.isBuffer(content)?content:Buffer.from(content),Buffer.from(`\r\n--${boundary}--\r\n`)]);
      const result=await php.run({scriptPath:'/wordpress/knowledge-quality.php',protocol:'https',method:'POST',headers:{'Content-Type':`multipart/form-data; boundary=${boundary}`},body,env:{AISA_KNOWLEDGE_CASE:name,AISA_KNOWLEDGE_PROBE:probe?'1':''},$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});
      if(result.errors)throw new Error(result.errors);const item=JSON.parse(result.text);if(item.status!==(probe?'OBSERVED':'PASS'))throw new Error('Knowledge case failed');cases.push(item);
    }}catch(error){save('FAIL');throw error;}
    save(probe?'OBSERVED':'PASS');console.log(JSON.stringify({status:probe?'OBSERVED':'PASS',checks:cases.reduce((sum,item)=>sum+item.checks,0),observations:cases.map(c=>c.observation)}));
  }else if (process.argv.includes('--diagnostic-check')) {
    php.writeFile('/wordpress/diagnostics.php',fs.readFileSync(path.join(root,'tests/wp-diagnostics.php')));
    const step=await php.run({scriptPath:'/wordpress/diagnostics.php',protocol:'https',$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});
    if(step.errors) throw new Error(step.errors);const report=JSON.parse(step.text);if(report.status!=='PASS')throw new Error('Diagnostics failed');
    report.plugin_sha256=sourceHashes;report.storage=storage;fs.writeFileSync(path.join(root,`outputs/diagnostics-${report.plugin_version}-${storage}.json`),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,checks:report.checks,storage}));
  } else if (process.argv.includes('--wire-check')) {
    php.writeFile('/wordpress/wire-seed.php',fs.readFileSync(path.join(root,'tests/wp-wire-seed.php')));
    php.writeFile('/wordpress/wp-content/mu-plugins/wire-peer.php',fs.readFileSync(path.join(root,'tests/wp-wire-peer.php')));
    const {wireContract}=await import('./wp-wire.mjs');const report=await wireContract(php,{deferMcp:process.argv.includes('--without-mcp')});report.storage=storage;report.plugin_sha256=sourceHashes;
    report.harness_sha256=Object.fromEntries(['tests/wp-wire.mjs','tests/wp-wire-seed.php','tests/wp-wire-peer.php','tests/wp-integration.mjs'].map(file=>[file,createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex').toUpperCase()]));
    fs.writeFileSync(path.join(root,`outputs/rest-wire-${report.plugin_version}-${storage}.json`),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,checks:report.checks,storage}));
  } else if (process.argv.includes('--notification-check')) {
    php.writeFile('/wordpress/notifications.php',fs.readFileSync(path.join(root,'tests/wp-notifications.php')));
    const step=await php.run({scriptPath:'/wordpress/notifications.php',protocol:'https',$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1'}});
    if(step.errors) throw new Error(step.errors);
    const report=JSON.parse(step.text); if(report.status!=='PASS'||report.storage!==storage) throw new Error('Notification contract failed');
    report.plugin_sha256=sourceHashes;
    report.harness_sha256=Object.fromEntries(['tests/wp-notifications.php','tests/wp-integration.mjs'].map(file=>[file,createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex').toUpperCase()]));
    fs.writeFileSync(path.join(root,`outputs/notification-${report.plugin_version}-${storage}.json`),JSON.stringify(report,null,2));console.log(JSON.stringify(report));
  } else if (process.argv.includes('--multisite-check')) {
    php.writeFile('/wordpress/multisite.php',fs.readFileSync(path.join(root,'tests/wp-multisite.php')));
    const phases=[];
    const harnessHashes=Object.fromEntries(['tests/wp-multisite.php','tests/wp-integration.mjs'].map(file=>[file,createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex').toUpperCase()]));
    const reportPath=path.join(root,`outputs/multisite-${archiveVersion||'source'}-${storage}.json`);
    // Replace obsolete partial-scope results immediately, even if a later phase fails.
    const saveReport=(status,error)=>fs.writeFileSync(reportPath,JSON.stringify({status,storage,checks:phases.reduce((sum,phase)=>sum+phase.checks,0),phases,error,plugin_sha256:sourceHashes,harness_sha256:harnessHashes,real_login_verified:false,external_transport_enabled:false},null,2));
    saveReport('RUNNING');
    try { for(const phase of ['convert','setup','storage','isolation','fresh-second','global-delete','switched-removal','site-data-removal','removal-check']) {
      if(phase==='setup') {
        let config=php.readFileAsText('/wordpress/wp-config.php');
        config=config.replace(/define\('WP_HOME'.*?define\('WP_SITEURL'.*?;/,'');
        config=config.replace("$table_prefix='wp_';", "define('MULTISITE',true); define('SUBDOMAIN_INSTALL',false); define('DOMAIN_CURRENT_SITE','aisa.local.test'); define('PATH_CURRENT_SITE','/'); define('SITE_ID_CURRENT_SITE',1); define('BLOG_ID_CURRENT_SITE',1); $table_prefix='wp_';");
        php.writeFile('/wordpress/wp-config.php',config);
      }
      const result=await php.run({scriptPath:'/wordpress/multisite.php',protocol:'https',env:{AISA_MS_PHASE:phase,AISA_MS_STORAGE:storage},$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443',REMOTE_ADDR:'127.0.0.1',REQUEST_URI:['storage','fresh-second','site-data-removal'].includes(phase)?'/second/':'/'}});
      if(result.errors) throw new Error('Multisite '+phase+': '+result.errors.slice(0,1200));
      let outcome; try { outcome=JSON.parse(result.text); } catch { throw new Error('Multisite '+phase+' returned non-JSON: '+result.text.replace(/<style[^>]*>[\s\S]*?<\/style>/g,'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').slice(0,700)); }
      if(outcome.status!=='PASS') throw new Error('Multisite failed: '+phase); phases.push(outcome);
    } } catch(error) { saveReport('FAIL',error.message); throw error; }
    saveReport('PASS'); console.log(JSON.stringify(phases));
  } else if (process.argv.includes('--upgrade-check')) {
    php.writeFile('/wordpress/upgrade.php',fs.readFileSync(path.join(root,'tests/wp-upgrade.php')));
    // Test-only metadata failure injection loads before init; never present in packaged source.
    php.writeFile('/wordpress/wp-content/mu-plugins/owner-failure.php',`<?php add_filter('update_post_metadata',function($pre,$id,$key){ return getenv('AISA_FAIL_OWNER_META') && $key==='aisa_ticket_owner'?false:$pre; },PHP_INT_MAX,3);`);
    const phases=[];
    for (const phase of ['seed','busy','fail-meta','migrate','inactive','check']) {
      if (phase==='busy') for (const [entry,bytes] of Object.entries(candidateFiles)) php.writeFile('/wordpress/wp-content/plugins/'+entry,bytes);
      const step=await php.run({scriptPath:'/wordpress/upgrade.php',protocol:'https',env:{AISA_UPGRADE_PHASE:phase,AISA_FAIL_OWNER_META:phase==='fail-meta'?'1':''},$_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443'}});
      if (step.errors) throw new Error(step.errors);
      const outcome=JSON.parse(step.text); if (outcome.status!=='PASS') throw new Error('Upgrade failed: '+phase); phases.push(outcome);
    }
    const version=/Version:\s*(\d+\.\d+\.\d+)/.exec(php.readFileAsText('/wordpress/wp-content/plugins/ai-support-agent/ai-support-agent.php'))?.[1];
    if(!version) throw new Error('Upgrade candidate version missing');
    const report={status:'PASS',from:'0.1.7',to:version,storage,checks:phases.reduce((sum,phase)=>sum+phase.checks,0),phases,plugin_sha256:sourceHashes,real_login_verified:false,external_transport_enabled:false};
    fs.writeFileSync(path.join(root,`outputs/upgrade-${version}-${storage}.json`),JSON.stringify(report,null,2)); console.log(JSON.stringify(report.phases));
  } else if (process.argv.includes('--quality-probe')) {
    php.writeFile('/wordpress/quality-probe.php', fs.readFileSync(path.join(root, 'tests/wp-quality-probe.php')));
    const probe = await php.run({ scriptPath: '/wordpress/quality-probe.php', protocol: 'https', $_SERVER: { HTTP_HOST: 'aisa.local.test', HTTPS: 'on', SERVER_PORT: '443' } });
    if (probe.errors) throw new Error(probe.errors);
    const report = JSON.parse(probe.text); report.plugin_sha256 = sourceHashes;
    fs.writeFileSync(path.join(root, `outputs/quality-baseline-${report.plugin_version}-${storage}.json`), JSON.stringify(report, null, 2));
    console.log(probe.text);
    if (report.status !== 'OBSERVED') throw new Error('Unexpected quality probe output');
    process.exitCode = 0;
  } else {
  php.writeFile('/wordpress/integration.php', fs.readFileSync(path.join(root, 'tests/wp-integration.php')));
  php.writeFile('/wordpress/memory-test.php', fs.readFileSync(path.join(root, 'tests/wp-memory.php')));
  php.writeFile('/wordpress/transport-test.php', fs.readFileSync(path.join(root, 'tests/wp-transport.php')));
  php.writeFile('/wordpress/transport-contract.php', fs.readFileSync(path.join(root, 'tests/transport-contract.php')));
  php.writeFile('/wordpress/inbox-test.php', fs.readFileSync(path.join(root, 'tests/wp-inbox.php')));
  php.writeFile('/wordpress/inbox-contract.php', fs.readFileSync(path.join(root, 'tests/inbox-contract.php')));
  php.writeFile('/wordpress/customer-tickets.php', fs.readFileSync(path.join(root, 'tests/wp-customer-tickets.php')));
  php.writeFile('/wordpress/customer-ticket-contract.php', fs.readFileSync(path.join(root, 'tests/customer-ticket-contract.php')));
  const integration = await php.run({ scriptPath: '/wordpress/integration.php', protocol: 'https', env:{AISA_DEFER_MCP:process.argv.includes('--without-mcp')?'1':''}, $_SERVER: { HTTP_HOST: 'aisa.local.test', HTTPS: 'on', SERVER_PORT: '443' } });
  console.log(integration.text);
  if (integration.errors) throw new Error(integration.errors);
  const result = JSON.parse(integration.text);
  result.plugin_sha256 = sourceHashes;
  if (result.status !== 'PASS' || result.order_storage !== storage) throw new Error('Unexpected integration result or storage mode');
  php.writeFile('/wordpress/download-test.php', fs.readFileSync(path.join(root, 'tests/wp-download.php')));
  result.download_handler = [];
  for (const name of ['allowed', 'anonymous', 'wrong-owner', 'forged-order', 'forged-email', 'array-argument', 'expired', 'exhausted', 'pending', 'refunded', 'bearer-policy', 'revoked']) {
    const download = await php.run({ scriptPath: '/wordpress/download-test.php', protocol: 'https', env: { AISA_DOWNLOAD_CASE: name }, $_SERVER: { HTTP_HOST: 'aisa.local.test', HTTPS: 'on', SERVER_PORT: '443' } });
    if (download.errors) throw new Error(download.errors);
    if (name === 'allowed' || name === 'bearer-policy') {
      if (download.text !== 'Synthetic download fixture only.') throw new Error('Permitted synthetic file was not streamed by WooCommerce');
      result.download_handler.push({ case: name, status: 'PASS', file_streamed: true, login_required: name !== 'bearer-policy' });
    } else {
      const denied = JSON.parse(download.text);
      if (!denied.denied || ![302, 403, 404].includes(denied.status)) throw new Error('Unexpected download denial for ' + name);
      result.download_handler.push({ case: name, status: 'PASS', http_status: denied.status });
    }
  }
  if (result.inbox_checks) {
    if (versionAtLeast(result.plugin_version,'0.1.8')) {
      php.writeFile('/wordpress/quality.php', fs.readFileSync(path.join(root, 'tests/wp-quality.php')));
      const quality = await php.run({ scriptPath: '/wordpress/quality.php', protocol: 'https', $_SERVER: { HTTP_HOST: 'aisa.local.test', HTTPS: 'on', SERVER_PORT: '443' } });
      if (quality.errors) throw new Error(quality.errors);
      result.account_lifecycle = JSON.parse(quality.text);
      if (result.account_lifecycle.status !== 'PASS') throw new Error('Account lifecycle quality did not pass');
    }
    php.writeFile('/wordpress/inbox-lifecycle.php', fs.readFileSync(path.join(root, 'tests/wp-inbox-lifecycle.php')));
    const lifecycle = await php.run({ scriptPath: '/wordpress/inbox-lifecycle.php', protocol: 'https', $_SERVER: { HTTP_HOST: 'aisa.local.test', HTTPS: 'on', SERVER_PORT: '443' } });
    if (lifecycle.errors) throw new Error(lifecycle.errors);
    result.inbox_lifecycle = JSON.parse(lifecycle.text);
    if (result.inbox_lifecycle.status !== 'PASS') throw new Error('Inbox lifecycle did not pass');
    if (versionAtLeast(result.plugin_version,'0.1.8')) {
      php.writeFile('/wordpress/inactive-uninstall.php', fs.readFileSync(path.join(root, 'tests/wp-inactive-uninstall.php')));
      result.inactive_uninstall = [];
      for (const phase of ['activate','seed','default','failure','false-success','delete']) {
        const step = await php.run({ scriptPath:'/wordpress/inactive-uninstall.php', protocol:'https', env:{AISA_QUALITY_PHASE:phase}, $_SERVER:{HTTP_HOST:'aisa.local.test',HTTPS:'on',SERVER_PORT:'443'} });
        if (step.errors) throw new Error(step.errors);
        const outcome=JSON.parse(step.text); if (outcome.status!=='PASS') throw new Error('Inactive uninstall failed: '+phase);
        result.inactive_uninstall.push(outcome);
      }
    }
  }
  fs.mkdirSync(path.join(root, 'outputs'), { recursive: true });
  const versioned = path.join(root, `outputs/wp-integration-${result.plugin_version}${process.argv.includes('--without-mcp')?'-no-mcp':''}-${storage}.json`);
  if (fs.existsSync(versioned) && fs.existsSync(path.join(root, `dist/ai-support-agent-${result.plugin_version}.zip`))) {
    const previous = JSON.parse(fs.readFileSync(versioned, 'utf8'));
    const sorted = hashes => JSON.stringify(Object.entries(hashes).sort());
    if (sorted(previous.plugin_sha256) !== sorted(sourceHashes)) throw new Error('Refusing to replace delivered-version evidence with different source hashes. Bump the review version first.');
  }
  fs.writeFileSync(path.join(root, `outputs/wp-integration-${storage}.json`), JSON.stringify(result, null, 2));
  if (/^\d+\.\d+\.\d+$/.test(result.plugin_version)) fs.writeFileSync(versioned, JSON.stringify(result, null, 2));
  console.log(`PASS: ${storage}, ${result.checks} real WP/Woo checks and ${result.download_handler.length} actual download-handler cases. No database, users or download URLs exported.`);
  }
} finally {
  php.exit();
}
