/**
 * Ethereum Bridge Gateway Routes Module
 * 
 * This module defines all HTTP API endpoints for the Ethereum bridge service.
 * It handles wallet operations including deposits, withdrawals, balance queries,
 * and wallet creation for both ETH and ERC-20 tokens.
 * 
 * @module ethereum/bridge/gateways
 */

const bodyParser = require('body-parser')
const methods = require('../methods');
const axios = require('axios');

/**
 * Initializes all gateway routes for the Express application.
 * 
 * @param {Object} app - Express application instance
 * @returns {void}
 */
const gateways = (app) => {

    // Configure body parser middleware for JSON and URL-encoded requests
    app.use( bodyParser.json() );
    app.use(bodyParser.urlencoded({
        extended: true
    }));

    /**
     * POST /wallet/transfer/to/main
     * 
     * Transfers ETH from a deposit wallet to the main system wallet.
     * Called when a deposit is detected and needs to be consolidated.
     * Updates the deposit status in the database upon completion or failure.
     * 
     * @body {number} id - Deposit ID for database tracking
     * @body {string} address - Source wallet address
     * @body {string} private_key - Private key for signing
     * @body {string} to - Main wallet destination address
     * @body {number} amount - Amount to transfer
     * @body {boolean} fee - Whether to deduct gas from amount
     */
    app.post('/wallet/transfer/to/main', function (req, res) {
        try {
            console.log("Trying to transfer to the main wallet");
            methods.walletTransferToMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Error instance received");
                    console.log(receipt);

                    // Update deposit status to failed on error
                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('ETH Deposit Failed with id ' + req.body.id);
                    });
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Tx Confirmed ' + receipt.transactionHash);

                    // Update deposit status to processed on success
                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['processed', req.body.id],
                    }).then(data => {
                        console.log('ETH Deposit Processed with id ' + req.body.id);
                    });
                }
            });
            res.end(JSON.stringify({ message: "Started transferring to main wallet" }));
        } catch (error) {
            console.log(error)
            res.end(JSON.stringify({ message: "Not successful" }));
        }

    });

    /**
     * POST /wallet/withdraw/eth
     * 
     * Withdraws ETH from the main wallet to an external address.
     * Called when a user requests a withdrawal. Updates withdrawal
     * status and transaction hash in the database.
     * 
     * @body {number} id - Withdrawal ID for database tracking
     * @body {string} address - Main wallet address
     * @body {string} private_key - Private key for signing
     * @body {string} to - Destination wallet address
     * @body {number} amount - Amount to withdraw
     */
    app.post('/wallet/withdraw/eth', function (req, res) {

        console.log("Trying to withdraw from the main wallet");

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Error instance received");
                    console.log(receipt);

                    // Mark withdrawal as failed in database
                    global.database.query({
                        text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('Eth Withdraw Tx Failed with id ' + req.body.id);
                    });

                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Eth Withdraw Tx Confirmed ' + receipt.transactionHash + ' with id ' + req.body.id);

                    // Store transaction hash for user reference
                    global.database.query({
                        text: 'UPDATE withdrawals SET txn = $1 WHERE id = $2',
                        values: [receipt.transactionHash, req.body.id],
                    }).then(data => {
                        console.log('Eth Withdraw Tx Confirmed updated');
                    });

                }
            });
            res.end(JSON.stringify({ message: "Started eth withdrawing from the main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
            return console.log(error);
        }
    });

    /**
     * POST /wallet/withdraw/erc
     * 
     * Withdraws ERC-20 tokens from the main wallet to an external address.
     * Similar to ETH withdrawal but handles token contract interactions.
     * 
     * @body {number} id - Withdrawal ID for database tracking
     * @body {string} address - Main wallet address
     * @body {string} private_key - Private key for signing
     * @body {string} to - Destination wallet address
     * @body {number} amount - Amount to withdraw
     * @body {string} contract - ERC-20 token contract address
     */
    app.post('/wallet/withdraw/erc', function (req, res) {

        console.log("Trying to withdraw erc from the main wallet");

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Error instance received");
                    console.log(receipt);

                    // Mark withdrawal as failed in database
                    global.database.query({
                        text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('Erc Withdraw Tx Failed with id ' + req.body.id);
                    });

                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Erc Withdraw Tx Confirmed ' + receipt.transactionHash + ' with id ' + req.body.id);

                    // Store transaction hash for user reference
                    global.database.query({
                        text: 'UPDATE withdrawals SET txn = $1 WHERE id = $2',
                        values: [receipt.transactionHash, req.body.id],
                    }).then(data => {
                        console.log('Erc Withdraw Tx Confirmed updated');
                    });

                }
            });
            res.end(JSON.stringify({ message: "Started erc withdrawing from the main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
        }
    });

    /**
     * POST /wallet/balance/eth
     * 
     * Retrieves the ETH balance of a wallet address.
     * Returns the balance in ETH (not wei) for readability.
     * 
     * @body {string} address - Wallet address to check
     * @returns {Object} { success: boolean, message: balance or error }
     */
    app.post('/wallet/balance/eth', function (req, res) {

        console.log("Get ETH balance of " + req.body.address);

        try {
            methods.getAccountBalance(req.body.address, '', (balance) => {
                if (balance instanceof Error) {
                    console.log("Error instance received");
                    console.log(balance);
                    res.end(JSON.stringify({ success: false, message: "Could not fetch balance" }));
                } else {
                    // Convert from wei to ETH (18 decimals)
                    res.end(JSON.stringify({ success: true, message: methods.weiToNumber(balance, 18) }));
                }
            });

        } catch (error) {
            console.log(error);
            res.end(JSON.stringify({ success: false, message: "Could not fetch balance" }));
        }
    });

    /**
     * POST /wallet/balance/erc
     * 
     * Retrieves the ERC-20 token balance of a wallet address.
     * Automatically handles token decimals for proper formatting.
     * 
     * @body {string} address - Wallet address to check
     * @body {string} contract - ERC-20 token contract address
     * @returns {Object} { success: boolean, message: balance or error }
     */
    app.post('/wallet/balance/erc', function (req, res) {

        console.log("Get ERC balance of " + req.body.address + " with contract " + req.body.contract);

        try {
            methods.getAccountBalance(req.body.address, req.body.contract, (balance, decimals) => {
                if (balance instanceof Error) {
                    console.log("Error instance received");
                    console.log(balance);
                    res.end(JSON.stringify({ success: false, message: "Could not fetch balance" }));
                } else {
                    // Convert using token's specific decimal places
                    res.end(JSON.stringify({ success: true, message: methods.weiToNumber(balance,decimals) }));
                }
            });

        } catch (error) {
            console.log(error);
            res.end(JSON.stringify({ success: false, message: "Could not fetch balance" }));
        }
    });

    /*************************************************************************************************************
     * ERC-20 TOKEN DEPOSIT ENDPOINTS
     * These endpoints handle the consolidation of ERC-20 tokens from deposit wallets
     *************************************************************************************************************/

    /**
     * POST /wallet/transfer/to/main/wallet/erc
     * 
     * Transfers ERC-20 tokens from a deposit wallet to the main wallet.
     * This is a two-step process: first sends gas fee, then transfers tokens.
     * Updates deposit status in database throughout the process.
     * 
     * @body {number} id - Deposit ID for tracking
     * @body {string} address - Deposit wallet address containing tokens
     * @body {string} wallet - Main system wallet address
     * @body {string} contract - ERC-20 token contract address
     * @body {string} wei - Token amount in smallest unit
     * @body {string} private_key - Main wallet private key (for gas)
     * @body {string} address_private_key - Deposit wallet private key (for tokens)
     */
    app.post('/wallet/transfer/to/main/wallet/erc', function (req, res) {

        console.log("Trying to transfer erc token to the main wallet");
        try {
            methods.walletTransferErcToMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Error instance received");
                    console.log(receipt);

                    // Mark deposit as failed on error
                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('Erc Deposit Failed with id ' + req.body.id);
                    });
                } else {
                    console.log(receipt);
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {

                    // Mark deposit as successfully processed
                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['processed', req.body.id],
                    }).then(data => {
                        console.log('Erc Deposit Transferred with id ' + req.body.id);
                    });


                    console.log('Erc Tx Confirmed ' + receipt.transactionHash + ' for ' + req.body.id);
                }
            });
            res.end(JSON.stringify({ message: "Started transferring erc to main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
            return console.log(error);
        }

    });

    /**
     * GET /wallet/create
     * 
     * Creates a new Ethereum wallet with a randomly generated private key.
     * Used when users need new deposit addresses.
     * 
     * @returns {Object} Wallet object with address and privateKey
     */
    app.get('/wallet/create', function (req, res) {
        let accountWallet = methods.walletCreate();
        res.send(JSON.stringify(accountWallet));
    });

    /*************************************************************************************************************
     * MERCHANT MODULE ENDPOINTS
     * 
     * These endpoints are used by the Merchant Acquiring module for payment processing.
     * Unlike platform deposit/withdrawal endpoints, these do NOT update database tables directly.
     * Instead, they return results for the PHP backend to handle business logic.
     *************************************************************************************************************/

    /**
     * POST /wallet/transfer/eth
     * 
     * Transfers ETH from the hot wallet to a merchant's destination address.
     * Used for merchant payouts in native ETH.
     * 
     * @body {string} address - Hot wallet address
     * @body {string} private_key - Hot wallet private key
     * @body {string} to - Merchant destination address
     * @body {number} amount - Amount to transfer
     * @returns {Object} { success: boolean, message: string }
     */
    app.post('/wallet/transfer/eth', function (req, res) {
        console.log("Merchant: Transfer ETH to destination");
        console.log(req.body);

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Merchant ETH transfer error:", receipt);
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Merchant ETH Transfer Confirmed: ' + receipt.transactionHash);
                }
            });

            res.json({ success: true, message: "ETH transfer initiated" });
        } catch (error) {
            console.log(error);
            res.json({ success: false, error: error.message });
        }
    });

    /**
     * POST /wallet/transfer/erc
     * 
     * Transfers ERC-20 tokens from the hot wallet to a merchant's destination.
     * Used for merchant payouts in ERC-20 tokens (USDT, USDC, etc.).
     * 
     * @body {string} address - Hot wallet address
     * @body {string} private_key - Hot wallet private key
     * @body {string} to - Merchant destination address
     * @body {number} amount - Amount to transfer
     * @body {string} contract - ERC-20 token contract address
     * @returns {Object} { success: boolean, message: string }
     */
    app.post('/wallet/transfer/erc', function (req, res) {
        console.log("Merchant: Transfer ERC-20 to destination");
        console.log(req.body);

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Merchant ERC transfer error:", receipt);
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Merchant ERC Transfer Confirmed: ' + receipt.transactionHash);
                }
            });

            res.json({ success: true, message: "ERC transfer initiated" });
        } catch (error) {
            console.log(error);
            res.json({ success: false, error: error.message });
        }
    });

    /**
     * POST /wallet/merchant/sweep/eth
     * 
     * Sweeps ETH from a merchant invoice deposit address to the hot wallet.
     * Waits for confirmation before responding (synchronous with 30s timeout).
     * Returns the transaction hash for tracking.
     * 
     * @body {string} address - Deposit address to sweep from
     * @body {string} private_key - Deposit address private key
     * @body {string} to - Hot wallet destination
     * @body {number} amount - Amount to sweep
     * @body {boolean} fee - Whether to deduct gas from amount
     * @returns {Object} { success: boolean, txHash?: string, message?: string }
     */
    app.post('/wallet/merchant/sweep/eth', function (req, res) {
        console.log("Merchant: Sweep ETH to hot wallet");
        console.log(req.body);

        let responded = false;

        try {
            methods.signMainWalletEthTransaction(req.body, (error) => {
                if (error instanceof Error && !responded) {
                    responded = true;
                    console.log("Merchant ETH sweep error:", error);
                    res.json({ success: false, error: error.message });
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash && !responded) {
                    responded = true;
                    console.log('Merchant ETH Sweep Confirmed: ' + receipt.transactionHash);
                    res.json({ success: true, txHash: receipt.transactionHash });
                }
            });

            // Timeout after 30 seconds if no confirmation received
            setTimeout(() => {
                if (!responded) {
                    responded = true;
                    res.json({ success: true, message: "Sweep initiated, waiting for confirmation" });
                }
            }, 30000);

        } catch (error) {
            if (!responded) {
                responded = true;
                console.log(error);
                res.json({ success: false, error: error.message });
            }
        }
    });

    /**
     * POST /wallet/merchant/sweep/erc
     * 
     * Sweeps ERC-20 tokens from a merchant invoice deposit address to the hot wallet.
     * Performs two-step process: sends gas fee first, then transfers tokens.
     * Has a longer 60s timeout due to the two-step nature.
     * 
     * @body {string} address - Deposit address containing tokens
     * @body {string} wallet - Hot wallet destination
     * @body {string} contract - ERC-20 token contract address
     * @body {string} wei - Token amount in smallest unit
     * @body {string} private_key - Hot wallet private key (for gas)
     * @body {string} address_private_key - Deposit wallet private key (for tokens)
     * @returns {Object} { success: boolean, txHash?: string, message?: string }
     */
    app.post('/wallet/merchant/sweep/erc', function (req, res) {
        console.log("Merchant: Sweep ERC-20 to hot wallet");
        console.log(req.body);

        let responded = false;

        try {
            methods.sendFeeAndWithdrawToMain(req.body, (error) => {
                if (error instanceof Error && !responded) {
                    responded = true;
                    console.log("Merchant ERC sweep error:", error);
                    res.json({ success: false, error: error.message });
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash && !responded) {
                    responded = true;
                    console.log('Merchant ERC Sweep Confirmed: ' + receipt.transactionHash);
                    res.json({ success: true, txHash: receipt.transactionHash });
                }
            });

            // Longer timeout for ERC sweeps (two-step process)
            setTimeout(() => {
                if (!responded) {
                    responded = true;
                    res.json({ success: true, message: "Sweep initiated, waiting for confirmation" });
                }
            }, 60000);

        } catch (error) {
            if (!responded) {
                responded = true;
                console.log(error);
                res.json({ success: false, error: error.message });
            }
        }
    });
}

exports.gateways = gateways;
