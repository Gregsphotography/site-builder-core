import { build } from 'esbuild';
import { readFile, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)), '..');
const entries=[["css/site-builder.css", "site-builder.css"], ["js/preview.js", "preview.js"], ["js/overlays.js", "overlays.js"]];
for(const [entry,target] of entries){
 const outfile=resolve(root,'resources/dist',target);
 const options={entryPoints:[resolve(root,'resources',entry)],outfile,minify:true,bundle:true,format:'esm',target:'es2022',legalComments:'none',logLevel:'warning'};
 if(process.argv.includes('--check')){const result=await build({...options,write:false});if(await readFile(outfile,'utf8')!==result.outputFiles[0].text)throw new Error('Rebuild required: '+target);}
 else{await mkdir(dirname(outfile),{recursive:true});await build(options);}
}
console.log('Package assets verified.');
