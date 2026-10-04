const bodyParser = require('body-parser')
const methods = require('../methods');
const axios = require('axios');

const gateways = (app) => {

    app.use( bodyParser.json() );
    app.use(bodyParser.urlencoded({
        extended: true
    }));

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
                        console.log('ETH Deposit Failed with id ' + req.body.id);
                    });
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Tx Confirmed ' + receipt.transactionHash);

                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['processed', req.body.id],
                    }).then(data => {
                        console.log('Matic Deposit Processed with id ' + req.body.id);
                    });
                }
            });
            res.end(JSON.stringify({ message: "Started transferring to main wallet" }));
        } catch (error) {
            console.log(error)
            res.end(JSON.stringify({ message: "Not successful" }));
        }

    });

    app.post('/wallet/withdraw/matic', function (req, res) {

        console.log("Trying to withdraw from the main wallet");

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Error instance received");
                    console.log(receipt);

                    global.database.query({
                        text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('Matic Withdraw Tx Failed with id ' + req.body.id);
                    });

                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Matic Withdraw Tx Confirmed ' + receipt.transactionHash + ' with id ' + req.body.id);

                    global.database.query({
                        text: 'UPDATE withdrawals SET txn = $1 WHERE id = $2',
                        values: [receipt.transactionHash, req.body.id],
                    }).then(data => {
                        console.log('Matic Withdraw Tx Confirmed updated');
                    });

                }
            });
            res.end(JSON.stringify({ message: "Started Matic withdrawing from the main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
            return console.log(error);
        }
    });

    app.post('/wallet/withdraw/matic20', function (req, res) {

        console.log("Trying to withdraw matic20 from the main wallet");

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Error instance received");
                    console.log(receipt);

                    global.database.query({
                        text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('Matic20 Withdraw Tx Failed with id ' + req.body.id);
                    });

                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Matic20 Withdraw Tx Confirmed ' + receipt.transactionHash + ' with id ' + req.body.id);

                    global.database.query({
                        text: 'UPDATE withdrawals SET txn = $1 WHERE id = $2',
                        values: [receipt.transactionHash, req.body.id],
                    }).then(data => {
                        console.log('Matic20 Withdraw Tx Confirmed updated');
                    });

                }
            });
            res.end(JSON.stringify({ message: "Started Matic20 withdrawing from the main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
        }
    });

    app.post('/wallet/balance/matic', function (req, res) {

        console.log("Get Matic balance of " + req.body.address);

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

    app.post('/wallet/balance/matic20', function (req, res) {

        console.log("Get Matic20 balance of " + req.body.address + " with contract " + req.body.contract);

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

    /*************************************************************************************************************
     * ************************************************************************************************************
     * Matic20
     */

    app.post('/wallet/transfer/to/main/wallet/matic20', function (req, res) {

        console.log("Trying to transfer Matic20 token to the main wallet");
        try {
            methods.walletTransferMatic20ToMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Error instance received");
                    console.log(receipt);

                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('Matic20 Deposit Failed with id ' + req.body.id);
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
                        console.log('Matic20 Deposit Transferred with id ' + req.body.id);
                    });


                    console.log('Matic20 Tx Confirmed ' + receipt.transactionHash + ' for ' + req.body.id);
                }
            });
            res.end(JSON.stringify({ message: "Started transferring Matic20 to main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
            return console.log(error);
        }

    });

    app.get('/wallet/create', function (req, res) {
        let accountWallet = methods.walletCreate();
        res.send(JSON.stringify(accountWallet));
    });

    /*************************************************************************************************************
     * MERCHANT MODULE ENDPOINTS
     * These endpoints do NOT update platform tables - they return results for PHP to handle
     *************************************************************************************************************/

    // Merchant: Transfer MATIC from hot wallet to destination
    app.post('/wallet/transfer/matic', function (req, res) {
        console.log("Merchant: Transfer MATIC to destination");
        console.log(req.body);

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Merchant MATIC transfer error:", receipt);
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Merchant MATIC Transfer Confirmed: ' + receipt.transactionHash);
                }
            });

            res.json({ success: true, message: "MATIC transfer initiated" });
        } catch (error) {
            console.log(error);
            res.json({ success: false, error: error.message });
        }
    });

    // Merchant: Transfer MATIC-20 from hot wallet to destination
    app.post('/wallet/transfer/matic20', function (req, res) {
        console.log("Merchant: Transfer MATIC-20 to destination");
        console.log(req.body);

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error) {
                    console.log("Merchant MATIC20 transfer error:", receipt);
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash) {
                    console.log('Merchant MATIC20 Transfer Confirmed: ' + receipt.transactionHash);
                }
            });

            res.json({ success: true, message: "MATIC20 transfer initiated" });
        } catch (error) {
            console.log(error);
            res.json({ success: false, error: error.message });
        }
    });

    // Merchant: Sweep MATIC from deposit address to hot wallet
    app.post('/wallet/merchant/sweep/matic', function (req, res) {
        console.log("Merchant: Sweep MATIC to hot wallet");
        console.log(req.body);

        let responded = false;

        try {
            methods.signMainWalletMaticTransaction(req.body, (error) => {
                if (error instanceof Error && !responded) {
                    responded = true;
                    console.log("Merchant MATIC sweep error:", error);
                    res.json({ success: false, error: error.message });
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash && !responded) {
                    responded = true;
                    console.log('Merchant MATIC Sweep Confirmed: ' + receipt.transactionHash);
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

    // Merchant: Sweep MATIC-20 from deposit address to hot wallet
    app.post('/wallet/merchant/sweep/matic20', function (req, res) {
        console.log("Merchant: Sweep MATIC-20 to hot wallet");
        console.log(req.body);

        let responded = false;

        try {
            methods.sendFeeAndWithdrawToMain(req.body, (error) => {
                if (error instanceof Error && !responded) {
                    responded = true;
                    console.log("Merchant MATIC20 sweep error:", error);
                    res.json({ success: false, error: error.message });
                }
            }, (confirmation, receipt) => {
                if (confirmation == 1 && receipt.transactionHash && !responded) {
                    responded = true;
                    console.log('Merchant MATIC20 Sweep Confirmed: ' + receipt.transactionHash);
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
