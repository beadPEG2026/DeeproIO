# Blockchain services

Node services for Ethereum, BNB, Polygon, TRON, Solana and TON. Shared runtime and custody code live in this directory. The `xlayer` directory contains helper code, not a separately packaged application.

## Install

Run `npm run install:all` in this directory. Each chain directory has its own dependency manifest. The shared environment loader reads `../.env`; alternatively set `ENV_PATH` to an environment file outside the repository.

Configure the database variables from the main application example. Chain connection settings are `APP_ETHEREUM_NODE`, `APP_BNB_NODE`, `APP_POLYGON_NODE`, `APP_TRONGRID_API`, `SOLANA_RPC_ENDPOINT`, `SOLANA_WS_ENDPOINT`, `TON_RPC_ENDPOINT`, `TON_API_ENDPOINT` and `TON_API_KEY`, according to the selected chain. Use the individual configuration files for supported port overrides and connection options. Custody authentication and signer settings must be provisioned separately; no signing keys are included.

## Run

From the required chain directory, run `node app.js`. Manage production processes with a service manager and keep bridge listeners on the private service network. Enable only services for configured networks. Chain requests, signed transactions and accounting confirmation are separate stages.

Each module retains its original copyright and license notices.
