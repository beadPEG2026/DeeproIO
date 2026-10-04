require('../env-loader');

global.rpc = process.env.SOLANA_RPC_ENDPOINT || 'https://api.mainnet-beta.solana.com';
global.solanaWsEndpoint = process.env.SOLANA_WS_ENDPOINT || undefined;

global.port = Number(process.env.APP_SOLANA_PORT || 18006);

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
