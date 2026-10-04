const mix = require('laravel-mix');
const path = require('path');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel applications. By default, we are compiling the CSS
 | file for the application as well as bundling up all the JS files.
 |
 */

mix.alias({
    ziggy: path.resolve('vendor/tightenco/ziggy/dist'),
});
mix.setPublicPath('public/alternative');
mix.js('resources/js/alternative.js', 'public/alternative/js/protected-file-3HnJAidsKJ1.js').vue()
    .postCss('resources/css/app.css', 'public/alternative/css/', [
        require('postcss-import'),
        require('tailwindcss'),
        require('autoprefixer'),
    ])
    .sass('resources/sass/site.scss', 'public/alternative/css/protected-file-3HnJAidsKJ1.css')
    .webpackConfig(require('./webpack.config'));

if (mix.inProduction()) {
    mix.version();
    mix.options({
        terser: {
            extractComments: false,
        }
    });
}


