require('../env-loader');

// Ethereum Node Url (wss based infura or full ethereum node url)
global.geth = process.env.APP_ETHEREUM_NODE || 'http://127.0.0.1:1';

// Ethereum Node Environment
global.geth_env = 'mainnet';
// Application Port

global.port = Number(process.env.APP_ETHEREUM_PORT || 18000);

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
