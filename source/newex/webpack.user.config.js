require('./build-ui-styles.cjs');
const path = require('path');
const webpack = require('webpack');
const {PagePreloadsPlugin} = require('./build-page-preloads.cjs');
let ProgressBarPlugin = require('progress-bar-webpack-plugin');

module.exports = {
    resolve: {
        alias: {
            '@': path.resolve('resources/js'),
            '{Template}': path.resolve('resources/js/Themes/default'),
        },
        fallback: {
            http: require.resolve('stream-http'),
            https: require.resolve('https-browserify'),
            crypto: require.resolve('crypto-browserify'),
            stream: require.resolve('stream-browserify'),
            buffer: require.resolve('buffer'),
        },
    },
    module: {
        rules: [
            {
                test: /\.template$/,
                loader: 'vue-template-loader',
            }
        ]
    },
    plugins: [
        new PagePreloadsPlugin(),
        new webpack.IgnorePlugin({
            resourceRegExp: /^\.\/(locale|index\.es\.js)$/,
            contextRegExp: /(moment$|react-tooltip\/dist$)/
        }),
        new webpack.ProvidePlugin({
            'window.Quill': 'quill'
        }),
        new ProgressBarPlugin()
    ],
    output: {
        publicPath: '/frontend/',
        chunkFilename: 'js/chunks/[name].[contenthash:12].js',
    }
};
