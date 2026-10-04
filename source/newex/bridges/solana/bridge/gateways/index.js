const bodyParser = require('body-parser')
const axios = require('axios');
const {
    web3,
    connection,
    transferSol, transferSpl,
} = require('../methods');
const {LAMPORTS_PER_SOL, PublicKey} = require("@solana/web3.js");
const {getAssociatedTokenAddress, TOKEN_PROGRAM_ID, getOrCreateAssociatedTokenAccount, getMint} = require("@solana/spl-token");

const gateways = (app) => {

    app.use( bodyParser.json() );
    app.use(bodyParser.urlencoded({
        extended: true
    }));

    // SOL Endpoints

    // Move funds to main system wallet
    app.post('/wallet/transfer/to/main', async function (req, res) {
        try {
            console.log("Trying to transfer to the main wallet");

            const fromKeypair = web3.Keypair.fromSecretKey(Buffer.from(req.body.private_key, "hex"));

            const balance = await connection.getBalance(fromKeypair.publicKey);

            if (balance <= 5000) throw new Error("Balance too low");

            let txn = await transferSol(fromKeypair, req.body.to, req.body.lamports)
            console.log(txn);
            if(txn) {
                global.database.query({
                    text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                    values: ['processed', req.body.id],
                }).then(data => {
                    console.log('SOL Deposit Processed with id ' + req.body.id);
                });
            } else {
                global.database.query({
                    text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                    values: ['failed', req.body.id],
                }).then(data => {
                    console.log('SOL Deposit Failed with id ' + req.body.id);
                });
            }

            res.end(JSON.stringify({message: "Started transferring to main wallet"}));
        } catch (error) {
            console.log(error)
            res.end(JSON.stringify({message: "Not successful"}));
        }
    });

    // Balance check for system wallet
    app.post('/wallet/balance/sol', async function (req, res) {

        console.log("Get SOL balance of " + req.body.address);

        try {
            const address = new PublicKey(req.body.address);

            const balance = await connection.getBalance(address);
            console.log(balance / LAMPORTS_PER_SOL)
            res.end(JSON.stringify({success: true, message: balance / LAMPORTS_PER_SOL }));

        } catch (error) {
            console.log(error);
            res.end(JSON.stringify({success: false, message: "Could not fetch balance"}));
        }
    });

    // Withdraw from main system wallet
    app.post('/wallet/withdraw/sol', async function (req, res) {
        try {
            console.log("Trying to withdraw from the main wallet");

            const fromKeypair = web3.Keypair.fromSecretKey(Buffer.from(req.body.private_key, "hex"));

            let lamports = req.body.amount * LAMPORTS_PER_SOL;

            let txn = await transferSol(fromKeypair, req.body.to, lamports, true)

            console.log(txn);

            if (txn) {
                console.log('SOL Withdraw Tx Confirmed ' + txn + ' with id ' + req.body.id);

                global.database.query({
                    text: 'UPDATE withdrawals SET txn = $1 WHERE id = $2',
                    values: [txn, req.body.id],
                }).then(data => {
                    console.log('SOL Withdraw Tx Confirmed updated');
                });
            } else {
                global.database.query({
                    text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                    values: ['failed', req.body.id],
                }).then(data => {
                    console.log('SOL Withdraw Tx Failed with id ' + req.body.id);
                });
            }

            res.end(JSON.stringify({message: "Started eth withdrawing from the main wallet"}));
        } catch (error) {

            global.database.query({
                text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                values: ['failed', req.body.id],
            }).then(data => {
                console.log('SOL Withdraw Tx Failed with id ' + req.body.id);
            });

            console.log(error)
            res.end(JSON.stringify({message: "Not successful"}));
        }

    });

    // END SOL Endpoints
    // *******************************************************************************
    // *******************************************************************************
    // *******************************************************************************
    // *******************************************************************************



    app.post('/wallet/withdraw/spl', async function (req, res) {

        console.log("Trying to withdraw SPL from the main wallet");

        try {

            const systemWallet = web3.Keypair.fromSecretKey(Buffer.from(req.body.private_key, "hex"));
            const publicAddress = new PublicKey(req.body.address);
            const destinationAddress = new PublicKey(req.body.to);
            const mint = new web3.PublicKey(req.body.contract);

            const mintInfo = await getMint(connection, mint);
            let lamports = Math.round(req.body.amount * Math.pow(10, mintInfo.decimals));;

            let txn = await transferSpl(systemWallet, publicAddress, destinationAddress, lamports, mint)

            console.log(txn);

            if (txn) {
                console.log('SOL SPL Withdraw Tx Confirmed ' + txn + ' with id ' + req.body.id);

                global.database.query({
                    text: 'UPDATE withdrawals SET txn = $1 WHERE id = $2',
                    values: [txn, req.body.id],
                }).then(data => {
                    console.log('SOL SPL Withdraw Tx Confirmed updated');
                });
            } else {
                global.database.query({
                    text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                    values: ['failed', req.body.id],
                }).then(data => {
                    console.log('SOL SPL Withdraw Tx Failed with id ' + req.body.id);
                });
            }

            res.end(JSON.stringify({message: "Started eth withdrawing from the main wallet"}));
        } catch (error) {

            global.database.query({
                text: 'UPDATE withdrawals SET status = $1 WHERE id = $2',
                values: ['failed', req.body.id],
            }).then(data => {
                console.log('SOL Withdraw Tx Failed with id ' + req.body.id);
            });

            console.log(error)
            res.end(JSON.stringify({message: "Not successful"}));
        }
    });


    app.post('/wallet/balance/spl', async function (req, res) {

        console.log("Get SPL balance of " + req.body.address);

        try {

            const walletAddress = new PublicKey(req.body.address);
            const mintAddress = new PublicKey(req.body.contract);

            // Get associated token account address
            const tokenAccountAddress = await getAssociatedTokenAddress(
                mintAddress,
                walletAddress,
                false,
                TOKEN_PROGRAM_ID
            );

            const balanceResponse = await connection.getTokenAccountBalance(tokenAccountAddress);
            const amount = balanceResponse.value.uiAmount;
            console.log(amount)
            res.end(JSON.stringify({success: true, message: amount}));

        } catch (error) {
            console.log(error);
            res.end(JSON.stringify({success: false, message: "Could not fetch balance"}));
        }
    });

    /*************************************************************************************************************
     * ************************************************************************************************************
     * SPL
     */

    app.post('/wallet/transfer/to/main/wallet/spl', async function (req, res) {

        console.log("Trying to transfer SOL SPL token to the main wallet");
        try {

            const MIN_SOL_LAMPORTS = 2100000; // Safe minimum for SPL transfer

            const systemWallet = web3.Keypair.fromSecretKey(Buffer.from(req.body.system_wallet_pk, "hex"));
            const systemWalletPublicKey= new PublicKey(req.body.system_wallet);

            const userWallet = web3.Keypair.fromSecretKey(Buffer.from(req.body.private_key, "hex"));
            const userWalletPublicKey = new PublicKey(req.body.address);

            const bal = await connection.getBalance(userWalletPublicKey);

            if (bal < MIN_SOL_LAMPORTS) {
                console.log("Trying to transfer SOL as a gas fee")

                let solTxn = await transferSol(systemWallet, req.body.address, MIN_SOL_LAMPORTS, true)
                console.log(solTxn);
            }

            const mint = new web3.PublicKey(req.body.mint);

            let txn = await transferSpl(userWallet, userWalletPublicKey, systemWalletPublicKey, req.body.lamports, mint)

            console.log(txn);

            global.database.query({
                text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                values: ['processed', req.body.id],
            }).then(data => {
                console.log('SOL SPL Deposit Processed with id ' + req.body.id);
            });

        } catch(error) {

            global.database.query({
                text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                values: ['failed', req.body.id],
            }).then(data => {
                console.log('SOL SPL Deposit Failed with id ' + req.body.id);
            });

            res.end(JSON.stringify({message: "Not successful"}));
            return console.log(error);
        }

    });

    app.get('/wallet/create', function (req, res) {
        res.send(JSON.stringify({'success': true}));
    });

    /*************************************************************************************************************
     * MERCHANT MODULE ENDPOINTS
     * These endpoints do NOT update platform tables - they return results for PHP to handle
     *************************************************************************************************************/

    // Merchant: Transfer SOL from hot wallet to destination
    app.post('/wallet/transfer', async function (req, res) {
        console.log("Merchant: Transfer SOL to destination");
        console.log(req.body);

        try {
            const fromKeypair = web3.Keypair.fromSecretKey(Buffer.from(req.body.private_key, "hex"));
            let lamports = Math.round(req.body.amount * LAMPORTS_PER_SOL);

            let txn = await transferSol(fromKeypair, req.body.address, lamports, true);

            console.log('Merchant SOL Transfer: ' + txn);

            if (txn) {
                res.json({ success: true, txHash: txn });
            } else {
                res.json({ success: false, error: "Transfer failed" });
            }
        } catch (error) {
            console.log(error);
            res.json({ success: false, error: error.message });
        }
    });

    // Merchant: Transfer SPL from hot wallet to destination
    app.post('/wallet/transfer/spl', async function (req, res) {
        console.log("Merchant: Transfer SPL to destination");
        console.log(req.body);

        try {
            const systemWallet = web3.Keypair.fromSecretKey(Buffer.from(req.body.private_key, "hex"));
            const systemWalletPublicKey = new PublicKey(req.body.wallet);
            const destinationAddress = new PublicKey(req.body.address);
            const mint = new web3.PublicKey(req.body.contract);

            const mintInfo = await getMint(connection, mint);
            let lamports = Math.round(req.body.amount * Math.pow(10, mintInfo.decimals));

            let txn = await transferSpl(systemWallet, systemWalletPublicKey, destinationAddress, lamports, mint);

            console.log('Merchant SPL Transfer: ' + txn);

            if (txn) {
                res.json({ success: true, txHash: txn });
            } else {
                res.json({ success: false, error: "SPL transfer failed" });
            }
        } catch (error) {
            console.log(error);
            res.json({ success: false, error: error.message });
        }
    });

    // Merchant: Sweep SOL from deposit address to hot wallet
    app.post('/wallet/merchant/sweep/sol', async function (req, res) {
        console.log("Merchant: Sweep SOL to hot wallet");
        console.log(req.body);

        try {
            const fromKeypair = web3.Keypair.fromSecretKey(Buffer.from(req.body.address_private_key, "hex"));
            const balance = await connection.getBalance(fromKeypair.publicKey);

            if (balance <= 5000) {
                res.json({ success: false, error: "Balance too low" });
                return;
            }

            let txn = await transferSol(fromKeypair, req.body.wallet, balance - 5000);

            console.log('Merchant SOL Sweep: ' + txn);

            if (txn) {
                res.json({ success: true, txHash: txn });
            } else {
                res.json({ success: false, error: "Sweep failed" });
            }
        } catch (error) {
            console.log(error);
            res.json({ success: false, error: error.message });
        }
    });

    // Merchant: Sweep SPL from deposit address to hot wallet
    app.post('/wallet/merchant/sweep/spl', async function (req, res) {

        console.log("Merchant: Sweep SPL to hot wallet");

        try {
            const MIN_SOL_LAMPORTS = 2100000;

            const systemWallet = web3.Keypair.fromSecretKey(Buffer.from(req.body.private_key, "hex"));
            const systemWalletPublicKey = new PublicKey(req.body.wallet);

            const userWallet = web3.Keypair.fromSecretKey(Buffer.from(req.body.address_private_key, "hex"));
            const userWalletPublicKey = new PublicKey(req.body.address);

            // Check if deposit address has enough SOL for gas
            const bal = await connection.getBalance(userWalletPublicKey);

            if (bal < MIN_SOL_LAMPORTS) {
                console.log("Sending SOL as gas fee to deposit address");
                let solTxn = await transferSol(systemWallet, req.body.address, MIN_SOL_LAMPORTS, true);
                console.log("Gas fee tx: " + solTxn);
            }

            const mint = new web3.PublicKey(req.body.contract);
            const mintInfo = await getMint(connection, mint);
            let lamports = Math.round(req.body.amount * Math.pow(10, mintInfo.decimals));

            let txn = await transferSpl(userWallet, userWalletPublicKey, systemWalletPublicKey, lamports, mint);

            console.log('Merchant SPL Sweep: ' + txn);

            if (txn) {
                res.json({ success: true, txHash: txn });
            } else {
                res.json({ success: false, error: "SPL sweep failed" });
            }
        } catch (error) {
            console.log(error);
            res.json({ success: false, error: error.message });
        }
    });

};

exports.gateways = gateways;
