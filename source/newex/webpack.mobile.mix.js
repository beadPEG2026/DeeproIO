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
mix.setPublicPath('public/dependencies');
mix.js('resources/js/mobile.js', 'public/dependencies/js').vue()
    .postCss('resources/css/app.css', 'public/dependencies/css', [
        require('postcss-import'),
        require('tailwindcss'),
        require('autoprefixer'),
    ])
    .sass('resources/sass/mobile.scss', 'public/dependencies/css')
    .webpackConfig(require('./webpack.mobile.config'));

if (mix.inProduction()) {
    mix.version();
    mix.options({
        terser: {
            extractComments: false,
        }
    });
}


