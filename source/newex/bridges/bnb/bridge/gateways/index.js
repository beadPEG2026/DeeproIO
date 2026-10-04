/**
 * Binance Smart Chain (BSC) Bridge Gateway Routes Module
 * 
 * This module defines all HTTP API endpoints for the BSC bridge service.
 * It handles wallet operations including deposits, withdrawals, balance queries,
 * and wallet creation for both BNB and BEP-20 tokens.
 * 
 * @module bnb/bridge/gateways
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
     * Transfers BNB from a deposit wallet to the main system wallet.
     * Called when a deposit is detected and needs to be consolidated.
     * Updates the deposit status in the database upon completion or failure.
     * 
     * @body {number} id - Deposit ID for database tracking
     * @body {string} address - Source wallet address
     * @body {string} address_private_key - Private key for signing
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

                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('Bep Deposit Failed with id ' + req.body.id);
                    });
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Tx Confirmed ' + receipt.transactionHash);

                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['processed', req.body.id],
                    }).then(data => {
                        console.log('Bep Deposit Transferred with id ' + req.body.id);
                    });
                }
            });
            res.end(JSON.stringify({ message: "Started transferring to main wallet" }));
        } catch (error) {
            console.log(error)
            res.end(JSON.stringify({ message: "Not successful" }));
        }

    });

    app.post('/wallet/withdraw/bnb', function (req, res) {

            console.log("Trying to withdraw from the main wallet");
        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                console.log("Error instance received");
                if (receipt instanceof Error) {
                    console.log(receipt);

                    global.database.query({
                        text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('BNB Withdraw Tx Failed with id ' + req.body.id);
                    });
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('BNB Withdraw Tx Confirmed ' + receipt.transactionHash + ' with id ' + req.body.id);

                    global.database.query({
                        text: 'UPDATE withdrawals SET txn = $1 WHERE id = $2',
                        values: [receipt.transactionHash, req.body.id],
                    }).then(data => {
                        console.log('BNB Withdraw Tx Confirmed updated');
                    });
                }
            });
            res.end(JSON.stringify({ message: "Started BNB withdrawing from the main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
            return console.log(error);
        }
    });

    app.post('/wallet/withdraw/bep', function (req, res) {

        console.log("Trying to withdraw bep from the main wallet");

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Error instance received");
                    console.log(receipt);

                    global.database.query({
                        text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('BEP Withdraw Tx Failed with id ' + req.body.id);
                    });

                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('BEP Withdraw Tx Confirmed ' + receipt.transactionHash + ' with id ' + req.body.id);

                    global.database.query({
                        text: 'UPDATE withdrawals SET txn = $1 WHERE id = $2',
                        values: [receipt.transactionHash, req.body.id],
                    }).then(data => {
                        console.log('BEP Withdraw Tx Confirmed updated');
                    });

                }
            });
            res.end(JSON.stringify({ message: "Started bep withdrawing from the main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
        }
    });

    app.post('/wallet/balance/bnb', function (req, res) {

        console.log("Get BNB balance of " + req.body.address);

        try {
            methods.getAccountBalance(req.body.address, '', (balance) => {
                if (balance instanceof Error) {
                    console.log("Error instance received");
                    console.log(balance);
                    res.end(JSON.stringify({ success: false, message: "Could not fetch balance" }));
                } else {
                    res.end(JSON.stringify({ success: true, message: methods.weiToNumber(balance, 18) }));
                }
            });

        } catch (error) {
            console.log(error);
            res.end(JSON.stringify({ success: false, message: "Could not fetch balance" }));
        }
    });

    app.post('/wallet/balance/bep', function (req, res) {

        console.log("Get BEP balance of " + req.body.address + " with contract " + req.body.contract);

        try {
            methods.getAccountBalance(req.body.address, req.body.contract, (balance, decimals) => {
                if (balance instanceof Error) {
                    console.log("Error instance received");
                    console.log(balance);
                    res.end(JSON.stringify({ success: false, message: "Could not fetch balance" }));
                } else {
                    res.end(JSON.stringify({ success: true, message: methods.weiToNumber(balance,decimals) }));
                }
            });

        } catch (error) {
            console.log(error);
            res.end(JSON.stringify({ success: false, message: "Could not fetch balance" }));
        }
    });


    app.get('/blockchain/latest/block', function (req, res) {
        methods.getLatestBlock((block) => {
            res.end(block.toString());
        });
    });


    /*************************************************************************************************************
     * ************************************************************************************************************
     * bep
     */

    app.post('/wallet/transfer/to/main/wallet/bep', function (req, res) {

        console.log("Trying to transfer bep token to the main wallet");
        try {
            methods.walletTransferBepToMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Error instance received");
                    console.log(receipt);

                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('Bep Deposit Failed with id ' + req.body.id);
                    });
                } else {
                    console.log(receipt);
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {

                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['processed', req.body.id],
                    }).then(data => {
                        console.log('Bep Deposit Transferred with id ' + req.body.id);
                    });

                    console.log('Bep Tx Confirmed ' + receipt.transactionHash + ' for ' + req.body.id);
                }
            });
            res.end(JSON.stringify({ message: "Started transferring bep to main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
            return console.log(error);
        }

    });

    app.get('/wallet/create', function (req, res) {

        let accountWallet = methods.walletCreate();
        accountWallet.address = accountWallet.address.toLowerCase();

        res.send(JSON.stringify(accountWallet));
    });

    /*************************************************************************************************************
     * MERCHANT MODULE ENDPOINTS
     * These endpoints do NOT update platform tables - they return results for PHP to handle
     *************************************************************************************************************/

    // Merchant: Transfer BNB from hot wallet to destination
    app.post('/wallet/transfer/bnb', function (req, res) {
        console.log("Merchant: Transfer BNB to destination");
        console.log(req.body);

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Merchant BNB transfer error:", receipt);
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Merchant BNB Transfer Confirmed: ' + receipt.transactionHash);
                }
            });

            res.json({ success: true, message: "BNB transfer initiated" });
        } catch (error) {
            console.log(error);
            res.json({ success: false, error: error.message });
        }
    });

    // Merchant: Transfer BEP-20 from hot wallet to destination
    app.post('/wallet/transfer/bep', function (req, res) {
        console.log("Merchant: Transfer BEP-20 to destination");
        console.log(req.body);

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Merchant BEP transfer error:", receipt);
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Merchant BEP Transfer Confirmed: ' + receipt.transactionHash);
                }
            });

            res.json({ success: true, message: "BEP transfer initiated" });
        } catch (error) {
            console.log(error);
            res.json({ success: false, error: error.message });
        }
    });

    // Merchant: Sweep BNB from deposit address to hot wallet
    app.post('/wallet/merchant/sweep/bnb', function (req, res) {
        console.log("Merchant: Sweep BNB to hot wallet");
        console.log(req.body);

        let responded = false;

        try {
            methods.signMainWalletBnbTransaction(req.body, (error) => {
                if (error instanceof Error && !responded) {
                    responded = true;
                    console.log("Merchant BNB sweep error:", error);
                    res.json({ success: false, error: error.message });
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash && !responded) {
                    responded = true;
                    console.log('Merchant BNB Sweep Confirmed: ' + receipt.transactionHash);
                    res.json({ success: true, txHash: receipt.transactionHash });
                }
            });

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

    // Merchant: Sweep BEP-20 from deposit address to hot wallet
    app.post('/wallet/merchant/sweep/bep', function (req, res) {
        console.log("Merchant: Sweep BEP-20 to hot wallet");
        console.log(req.body);

        let responded = false;

        try {
            methods.sendFeeAndWithdrawToMain(req.body, (error) => {
                if (error instanceof Error && !responded) {
                    responded = true;
                    console.log("Merchant BEP sweep error:", error);
                    res.json({ success: false, error: error.message });
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash && !responded) {
                    responded = true;
                    console.log('Merchant BEP Sweep Confirmed: ' + receipt.transactionHash);
                    res.json({ success: true, txHash: receipt.transactionHash });
                }
            });

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
