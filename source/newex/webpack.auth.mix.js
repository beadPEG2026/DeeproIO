const mix = require('laravel-mix');
const path = require('path');
mix.alias({ziggy:path.resolve('vendor/tightenco/ziggy/dist')});
mix.setPublicPath('public/auth');
mix.js('resources/js/auth.js','public/auth/js/auth.js').vue()
 .webpackConfig({...require('./webpack.user.config'),output:{publicPath:'/auth/',chunkFilename:'js/[name].[contenthash:12].js'}});
if(mix.inProduction()){mix.version();mix.options({terser:{extractComments:false}});}
