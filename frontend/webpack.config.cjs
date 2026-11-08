'use strict';

const path = require('node:path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
  entry: {
    admin: ['./src/js/admin.js', './src/css/admin.css'],
    'theme-init': './src/js/theme-init.js',
  },
  output: {
    path: path.resolve(__dirname, '../public/assets'),
    filename: '[name].js',
    publicPath: '/assets/',
    clean: false,
  },
  devtool: false,
  module: {
    rules: [
      {
        test: /\.css$/,
        use: [
          MiniCssExtractPlugin.loader,
          'css-loader',
          {
            loader: 'postcss-loader',
            options: {
              postcssOptions: {
                plugins: {
                  '@tailwindcss/postcss': {},
                },
              },
            },
          },
        ],
      },
    ],
  },
  plugins: [
    new MiniCssExtractPlugin({ filename: 'admin.css' }),
  ],
  performance: {
    hints: 'warning',
    maxEntrypointSize: 250000,
    maxAssetSize: 200000,
  },
};
