const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'..'),build=require('../tests/package-review.cjs');
for(const dir of ['dist','outputs'])fs.mkdirSync(path.join(root,dir),{recursive:true});
const v=require('../package.json').version;const manifest=build(root,v);
const fixture=path.join(root,'tests/fixtures/legacy-0.1.7');for(const dir of ['dist','outputs'])fs.mkdirSync(path.join(fixture,dir),{recursive:true});
const old=build(fixture,'0.1.7'),legacy=fs.readFileSync(old.archive),sha=crypto.createHash('sha256').update(legacy).digest('hex').toUpperCase();
fs.copyFileSync(old.archive,path.join(root,'dist/ai-support-agent-0.1.7.zip'));fs.writeFileSync(path.join(root,'outputs/legacy-fixture-sha256.txt'),sha+'\n');
console.log(JSON.stringify({version:v,bytes:manifest.bytes,files:manifest.files.length,sha256:manifest.sha256}));
