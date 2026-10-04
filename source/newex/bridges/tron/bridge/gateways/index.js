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
                    console.log("Error instance received");
                    console.log(receipt);

                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('TRX Deposit Failed with id ' + req.body.id);
                    });
            }, (receipt) => {
                if (receipt.txid) {
                    console.log('Tx Confirmed ' + receipt.txid);

                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['processed', req.body.id],
                    }).then(data => {
                        console.log('TRX Deposit Processed with id ' + req.body.id);
                    });
                }
            });
            res.end(JSON.stringify({ message: "Started transferring to main wallet" }));
        } catch (error) {
            console.log(error)
            res.end(JSON.stringify({ message: "Not successful" }));
        }

    });

    app.post('/wallet/transfer/to/main/wallet/trc', function (req, res) {

        console.log("Trying to transfer trc token to the main wallet");
        try {
            methods.walletTransferTrcToMainWallet(req.body, (receipt) => {
                console.log("Error instance received");
                console.log(receipt);

                global.database.query({
                    text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                    values: ['failed', req.body.id],
                }).then(data => {
                    console.log('Trc Deposit Failed with id ' + req.body.id);
                });
            }, (receipt) => {
                if (receipt.txid) {

                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['processed', req.body.id],
                    }).then(data => {
                        console.log('Trc Deposit Transferred with id ' + req.body.id);
                    });


                    console.log('Trc Tx Confirmed ' + receipt.txid + ' for ' + req.body.id);
                }
            });
            res.end(JSON.stringify({ message: "Started transferring trc to main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
            return console.log(error);
        }

    });



    app.post('/wallet/withdraw/trx', function (req, res) {

        console.log("Trying to withdraw from the main wallet");

        try {
            methods.walletWithdrawFromMainWallet(req.body, callback => {
                console.log("Error instance received");
                console.log(callback);

                global.database.query({
                    text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                    values: ['failed', req.body.id],
                }).then(data => {
                    console.log('Trx Withdraw Tx Failed with id ' + req.body.id);
                });
            }, receipt => {
                if (receipt instanceof Error || (receipt && !receipt.txid)) {
                    console.log("Error instance received");
                    console.log(receipt);

                    global.database.query({
                        text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('Trx Withdraw Tx Failed with id ' + req.body.id);
                    });


                } else {

                    console.log('Trx Withdraw Tx Confirmed ' + receipt.txid + ' with id ' + req.body.id);

                    global.database.query({
                        text: 'UPDATE withdrawals SET txn = $1 WHERE id = $2',
                        values: [receipt.txid, req.body.id],
                    }).then(data => {
                        console.log('Trx Withdraw Tx Confirmed updated');
                    });

                    console.log(receipt);
                }

            });
            res.end(JSON.stringify({ message: "Started eth withdrawing from the main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
            return console.log(error);
        }
    });

    app.post('/wallet/withdraw/trc', function (req, res) {

        console.log("Trying to withdraw from the main wallet");

        try {
            methods.walletWithdrawFromMainWallet(req.body, (receipt) => {
                if (receipt instanceof Error || (receipt && !receipt.txid)) {
                    console.log("Error instance received");
                    console.log(receipt);

                    global.database.query({
                        text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                        values: ['failed', req.body.id],
                    }).then(data => {
                        console.log('Trc Withdraw Tx Failed with id ' + req.body.id);
                    });

                } else {

                    console.log('Trc Withdraw Tx Confirmed ' + receipt.txid + ' with id ' + req.body.id);

                    global.database.query({
                        text: 'UPDATE withdrawals SET txn = $1 WHERE id = $2',
                        values: [receipt.txid, req.body.id],
                    }).then(data => {
                        console.log('Trc Withdraw Tx Confirmed updated');
                    });


                    console.log(receipt);
                }

            });
            res.end(JSON.stringify({ message: "Started eth withdrawing from the main wallet" }));
        } catch (error) {
            res.end(JSON.stringify({ message: "Not successful" }));
            return console.log(error);
        }

    });

    app.post('/wallet/balance/trx', function (req, res) {

        console.log("Get Tron balance of " + req.body.address);

        try {
            methods.getAccountBalance(req.body.address, '', async (balance) => {
                if (balance instanceof Error) {
                    console.log("Error instance received");
                    console.log(balance);
                    res.end(JSON.stringify({success: false, message: "Could not fetch balance"}));
                } else {
                    let accountBalance = await methods.weiToNumber(balance, 6);
                    res.end(JSON.stringify({success: true, message: accountBalance}));
                }
            });

        } catch (error) {
            console.log(error);
            res.end(JSON.stringify({ success: false, message: "Could not fetch balance" }));
        }
    });

    app.post('/wallet/balance/trc', function (req, res) {

        console.log("Get TRC balance of " + req.body.address + " with contract " + req.body.contract);

        try {
            methods.getAccountBalance(req.body.address, req.body.contract, async (balance, decimals) => {
                if (balance instanceof Error) {
                    console.log("Error instance received");
                    console.log(balance);
                    res.end(JSON.stringify({success: false, message: "Could not fetch balance"}));
                } else {
                    let accountBalance = await methods.weiToNumber(balance, decimals);
                    res.end(JSON.stringify({success: true, message: accountBalance}));
                }
            });

        } catch (error) {
            console.log(error);
            res.end(JSON.stringify({ success: false, message: "Could not fetch balance" }));
        }
    });

    /*************************************************************************************************************
     * MERCHANT MODULE ENDPOINTS
     * These endpoints do NOT update platform tables - they return results for PHP to handle
     *************************************************************************************************************/

    // Merchant: Transfer TRX from hot wallet to destination
    app.post('/wallet/transfer/trx', function (req, res) {
        console.log("Merchant: Transfer TRX to destination");
        console.log(req.body);

        let responded = false;

        try {
            methods.walletWithdrawFromMainWallet(req.body, (error) => {
                if (!responded) {
                    console.log("Merchant TRX transfer error:", error);
                }
            }, (receipt) => {
                if (receipt && receipt.txid && !responded) {
                    responded = true;
                    console.log('Merchant TRX Transfer Confirmed: ' + receipt.txid);
                    res.json({ success: true, txHash: receipt.txid });
                } else if (!responded) {
                    responded = true;
                    res.json({ success: false, error: "No transaction ID returned" });
                }
            });

            setTimeout(() => {
                if (!responded) {
                    responded = true;
                    res.json({ success: true, message: "TRX transfer initiated" });
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

    // Merchant: Transfer TRC-20 from hot wallet to destination
    app.post('/wallet/transfer/trc', function (req, res) {
        console.log("Merchant: Transfer TRC-20 to destination");
        console.log(req.body);

        let responded = false;

        try {
            methods.walletWithdrawFromMainWallet(req.body, (error) => {
                if (!responded) {
                    console.log("Merchant TRC transfer error:", error);
                }
            }, (receipt) => {
                if (receipt && receipt.txid && !responded) {
                    responded = true;
                    console.log('Merchant TRC Transfer Confirmed: ' + receipt.txid);
                    res.json({ success: true, txHash: receipt.txid });
                } else if (!responded) {
                    responded = true;
                    res.json({ success: false, error: "No transaction ID returned" });
                }
            });

            setTimeout(() => {
                if (!responded) {
                    responded = true;
                    res.json({ success: true, message: "TRC transfer initiated" });
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

    // Merchant: Sweep TRX from deposit address to hot wallet
    app.post('/wallet/merchant/sweep/trx', function (req, res) {
        console.log("Merchant: Sweep TRX to hot wallet");
        console.log(req.body);

        let responded = false;

        try {
            methods.walletTransferToMainWallet(req.body, (error) => {
                if (!responded) {
                    console.log("Merchant TRX sweep error:", error);
                }
            }, (receipt) => {
                if (receipt && receipt.txid && !responded) {
                    responded = true;
                    console.log('Merchant TRX Sweep Confirmed: ' + receipt.txid);
                    res.json({ success: true, txHash: receipt.txid });
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

    // Merchant: Sweep TRC-20 from deposit address to hot wallet
    app.post('/wallet/merchant/sweep/trc', function (req, res) {
        console.log("Merchant: Sweep TRC-20 to hot wallet");
        console.log(req.body);

        let responded = false;

        try {
            methods.walletTransferTrcToMainWallet(req.body, (error) => {
                if (!responded) {
                    console.log("Merchant TRC sweep error:", error);
                }
            }, (receipt) => {
                if (receipt && receipt.txid && !responded) {
                    responded = true;
                    console.log('Merchant TRC Sweep Confirmed: ' + receipt.txid);
                    res.json({ success: true, txHash: receipt.txid });
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
