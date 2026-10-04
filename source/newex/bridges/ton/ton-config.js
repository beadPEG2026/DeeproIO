// TON bridge config with PostgreSQL connection (mirrors Solana setup)

require('../env-loader');

// Bridge port
global.port = Number(process.env.APP_TON_PORT || 18007);

// TON RPC config for TonWeb
global.ton = {
  endpoint: process.env.TON_RPC_ENDPOINT || process.env.TON_API_ENDPOINT || 'https://toncenter.com/api/v2/jsonRPC',
  apiKey: process.env.TON_API_KEY || undefined,
};

// Database credentials (same envs used by Solana bridge)
global.database_credentials = {
  host: process.env.DB_HOST,
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_DATABASE,
  port: parseInt(process.env.DB_PORT || '5432'),
  ssl: process.env.DB_SSL === 'true' ? {rejectUnauthorized: process.env.DB_SSL_VERIFY !== 'false'} : false,
};

require('../database').connect();
