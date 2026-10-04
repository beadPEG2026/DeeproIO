exports.connect = () => {
  if (global.database) return global.database;
  // Resolve pg from the chain module which owns the dependency.
  const {Pool} = require(require.resolve('pg', {paths:[process.cwd()]}));
  // A failed connection is discarded so subsequent probes can recover after startup or DB restart.
  global.database = new Pool({...global.database_credentials, max:4, idleTimeoutMillis:30000, connectionTimeoutMillis:5000, query_timeout:5000});
  global.database.on('error', () => { global.databaseReady=false; });
  global.database.query('SELECT 1').then(()=>{global.databaseReady=true;}).catch(()=>{global.databaseReady=false;});
  return global.database;
};
