require('../env-loader');

// Polygon Node Url (https based infura or full ethereum node url)
global.geth = process.env.APP_POLYGON_NODE || 'https://polygon-rpc.com';

// Ethereum Node Environment
global.geth_env = '80001';
// Application Port
global.port = Number(process.env.APP_POLYGON_PORT || 18003);

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
