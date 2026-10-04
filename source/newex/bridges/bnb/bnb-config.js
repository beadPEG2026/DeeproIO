// Bnb Node Url (ENDPOINT FROM THE OFFICIAL BINANCE BSC DOCS)
require('../env-loader');

global.geth = process.env.APP_BNB_NODE || 'https://bsc-dataseed.binance.org';

// Application Port
global.port = Number(process.env.APP_BSC_PORT || 18001);
// CEX Exchange Laravel App

// Default web3 configs
global.web3config = {
    keepAlive: true,
    timeout: 20000,
};

global.database_credentials = {
    host     : process.env.DB_HOST,
    user     : process.env.DB_USERNAME,
    password : process.env.DB_PASSWORD,
    database : process.env.DB_DATABASE,
    port : parseInt(process.env.DB_PORT),
    ssl: process.env.DB_SSL === 'true' ? {rejectUnauthorized: process.env.DB_SSL_VERIFY !== 'false'} : false,
};
