const fs=require('fs');const path=require('path');
const names=['brand','experience','theme','admin','workbench','layout','ui','mobile-refine','auth'];
const root=__dirname;
const css=names.map(name=>'/* deepro-'+name+'.css */\n'+fs.readFileSync(path.join(root,'public/css/deepro-'+name+'.css'),'utf8')).join('\n');
fs.writeFileSync(path.join(root,'public/css/deepro-site-bundle.css'),css);
