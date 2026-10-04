/**
 * Shared environment loader for all bridge modules
 *
 * Usage in bridge config files:
 *   require('../env-loader');
 *
 * Configuration:
 *   Set ENV_PATH environment variable to specify the path to .env file
 *   Example: ENV_PATH=/path/.env node app.js
 *
 *   If not set, it will try to auto-detect based on the current directory structure
 */

const path = require('path');
const fs = require('fs');

function loadEnv() {

    const possiblePaths = [
        process.env.ENV_PATH,
        path.resolve(__dirname, '..', '.env'),
        // When bridges folder is standalone project
        path.resolve(process.cwd(), '..', '.env'),
        // Direct parent
        path.resolve(__dirname, '..', '..', '.env'),
    ];

    for (const envPath of possiblePaths) {
        if (envPath && fs.existsSync(envPath)) {
            require('dotenv').config({ path: envPath });
            console.log(`[env-loader] Auto-detected .env from: ${envPath}`);
            return true;
        }
    }
    console.warn('[env-loader] Warning: No .env file found.');
    return false;
}

// Load environment on require
loadEnv();

// Export for programmatic use
module.exports = {
    loadEnv
};
