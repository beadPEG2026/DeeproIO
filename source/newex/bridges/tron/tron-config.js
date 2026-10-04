require('../env-loader');

// Tron Node Url
global.TRONGRID_API_KEY = process.env.APP_TRONGRID_API;

// Ethereum Node Environment
global.geth_env = 'mainnet';
// Application Port
global.port = Number(process.env.APP_TRON_PORT || 18002);

global.database_credentials = {
    host     : process.env.DB_HOST,
    user     : process.env.DB_USERNAME,
    password : process.env.DB_PASSWORD,
    database : process.env.DB_DATABASE,
    port : parseInt(process.env.DB_PORT),
    ssl: process.env.DB_SSL === 'true' ? {rejectUnauthorized: process.env.DB_SSL_VERIFY !== 'false'} : false,
};
