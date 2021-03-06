
let merge = require('webpack-merge');
let path = require('path');
let baseConfig = require(path.join(process.env.WEBPACK_BASE_PATH, 'webpack.config.base.js'));

let webpackConfig = {
    entry: {
        'css/scmc': './assets/less/scmc.less',
    },
};

module.exports = merge(baseConfig.get(__dirname), webpackConfig);