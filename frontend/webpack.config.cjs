// OPGateway's minimal subset of TailAdmin Free's Webpack workflow.
// Source and dependencies live outside Document Root in production.
const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const CopyWebpackPlugin = require('copy-webpack-plugin');
const TerserPlugin = require('terser-webpack-plugin');

module.exports = {
  mode: 'production',
  entry: path.resolve(__dirname, 'src/js/app.js'),
  devtool: false,
  output: {
    path: path.resolve(__dirname, '../public/assets/admin'),
    filename: 'app.js',
    clean: true,
    publicPath: '/assets/admin/',
  },
  module: {
    rules: [{
      test: /\.css$/,
      use: [MiniCssExtractPlugin.loader, 'css-loader', 'postcss-loader'],
    }],
  },
  plugins: [
    new MiniCssExtractPlugin({ filename: 'app.css' }),
    new CopyWebpackPlugin({
      patterns: [{ from: path.resolve(__dirname, 'src/js/theme-init.js'), to: 'theme-init.js' }],
    }),
  ],
  optimization: {
    minimize: true,
    minimizer: [
      new TerserPlugin({
        // Keep third-party copyright and license comments in app.js itself.
        // A separate app.js.LICENSE.txt would not be copied by our strict
        // cPanel allowlist and would leave a misleading missing-file banner.
        extractComments: false,
        terserOptions: { format: { comments: 'some' } },
      }),
    ],
  },
  target: 'web',
};
