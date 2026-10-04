/**
 * Polygon (MATIC) Bridge Methods Module
 * 
 * This module provides core functionality for interacting with the Polygon blockchain,
 * including wallet creation, MATIC and ERC-20/Polygon token transfers, balance queries,
 * and transaction signing.
 * 
 * Polygon is an EVM-compatible Layer 2 scaling solution with its own chain ID (137).
 * Uses custom chain configuration for proper transaction signing.
 * 
 * @module polygon/bridge/methods
 */

// Load libraries
require('../../polygon-config');
const Web3 = require('web3');
const web3Provider = require('web3-providers-http');
const Tx = require('ethereumjs-tx').Transaction;
const bigInt = require("big-integer");
const Common = require('ethereumjs-common').default;
let mysql = require('mysql');

const { Client } = require('pg');

// Initialize PostgreSQL database connection for deposit/withdrawal tracking
require('../../../database').connect();

/**
 * Polygon Mainnet chain configuration for transaction signing.
 * Required because Polygon uses different chain/network IDs than Ethereum.
 * 
 * Network ID: 137 (Polygon Mainnet)
 * Chain ID: 137 (Polygon Mainnet)
 * 
 * For Mumbai testnet, use networkId/chainId: 80001
 */
let MATIC_FORK = Common.forCustomChain(
    'mainnet',
    {
        name: 'Matic',
        networkId: 137,
        chainId: 137,
        url: 'https://polygon-rpc.com'
    },
    'istanbul'
);

// Initialize Web3 instance with configured Polygon node
let web3 = new Web3(new web3Provider(global.geth, global.web3config));

// Export BigNumber utility for precise arithmetic operations
const BN = web3.utils.BN;
exports.web3 = web3;

/**
 * Transfers MATIC from a user's wallet to the main system wallet.
 * This is typically called when a deposit is detected and needs to be consolidated.
 * 
 * @param {Object} wallet - Wallet configuration object
 * @param {string} wallet.address - Source wallet address
 * @param {string} wallet.private_key - Private key for signing the transaction
 * @param {string} wallet.to - Destination (main) wallet address
 * @param {number} wallet.amount - Amount of MATIC to transfer
 * @param {boolean} wallet.fee - If true, deducts gas fee from the transfer amount
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback, receives (confirmationNumber, receipt)
 * @returns {void}
 */
const walletTransferToMainWallet = (wallet, callback, confirmation) => {
    return signMainWalletMaticTransaction(wallet, callback, confirmation);
}
exports.walletTransferToMainWallet = walletTransferToMainWallet;

/**
 * Transfers ERC-20/Polygon tokens from a user's wallet to the main system wallet.
 * This handles the two-step process of sending gas fee first, then transferring tokens.
 * 
 * @param {Object} wallet - Wallet and token configuration object
 * @param {string} wallet.address - Source wallet address containing the tokens
 * @param {string} wallet.contract - Token contract address
 * @param {string} wallet.wallet - Main system wallet address
 * @param {string} wallet.wei - Token amount in smallest unit
 * @param {string} wallet.private_key - Private key of main wallet (for gas fee transfer)
 * @param {string} wallet.address_private_key - Private key of source wallet (for token transfer)
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback for the token transfer
 * @returns {void}
 */
const walletTransferMatic20ToMainWallet = (wallet, callback, confirmation) => {
    return sendFeeAndWithdrawToMain(wallet, callback, confirmation);
}

exports.walletTransferMatic20ToMainWallet = walletTransferMatic20ToMainWallet;

/**
 * Withdraws funds from the main wallet to an external address.
 * Automatically determines whether to use MATIC or token transfer based on contract presence.
 * 
 * @param {Object} wallet - Withdrawal configuration object
 * @param {string} wallet.address - Source (main) wallet address
 * @param {string} wallet.private_key - Private key for signing
 * @param {string} wallet.to - Destination wallet address
 * @param {number} wallet.amount - Amount to withdraw
 * @param {string} [wallet.contract] - Optional token contract address; if present, uses token transfer
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback function
 * @returns {void}
 */
const walletWithdrawFromMainWallet = (wallet, callback, confirmation) => {
    if (wallet.contract) {
        return signMainWalletMatic20Transaction(wallet, callback, confirmation);
    } else {
        return signMainWalletMaticTransaction(wallet, callback, confirmation);
    }
}
exports.walletWithdrawFromMainWallet = walletWithdrawFromMainWallet;

// Set signed transaction
const signMainWalletMaticTransaction = (wallet, callback, confirmation) => {

    ethGas((gasGweiPrice) => {
        try {
            console.log("Current eth gas costs " + gasGweiPrice);
            console.log(wallet);
            let gasLimit = 21000;
            let gasPrice = web3.utils.toWei(gasGweiPrice, 'Gwei');

            let amount = web3.utils.toWei(wallet.amount.toString());

            if (wallet.fee) {

                let gasFee = gasLimit * gasPrice;
                amount = amount - gasFee;

                if (amount < 0) {
                    console.log('Insufficient funds');
                    return;
                }
            }

            let privateKey = Buffer.from(wallet.private_key.substring(2, 66), 'hex');

            web3.eth.getTransactionCount(wallet.address, 'pending').then((txCount) => {

                let tx = new Tx({
                    nonce: web3.utils.numberToHex(txCount),
                    gasPrice: web3.utils.numberToHex(gasPrice),
                    gasLimit: web3.utils.numberToHex(gasLimit),
                    to: wallet.to,
                    value: web3.utils.numberToHex(amount)
                }, {'common': MATIC_FORK});

                tx.sign(privateKey);

                let transaction = web3.eth.sendSignedTransaction('0x' + tx.serialize().toString('hex'))

                transaction.on('confirmation', confirmation).catch(callback);

            }).catch((error) => {
                throw error;
            });
        } catch (error) {
            console.log(error);
            throw error;
        }
    });
};
exports.signMainWalletMaticTransaction = signMainWalletMaticTransaction;


const signMainWalletMatic20Transaction = (wallet, callback, confirmation) => {

    let tokenContract = createTokenContract(wallet.contract);

    tokenContract.methods.decimals().call((error, decimals) => {

        let amount = numberToWei(wallet.amount, decimals);

        let tokenData = tokenContract.methods.transfer(wallet.to, amount.toString()).encodeABI();

        web3.eth.estimateGas({from: wallet.address, to: wallet.contract, data: tokenData}).then((gasLimit) => {

            ethGas((gasGweiPrice) => {

                web3.eth.getTransactionCount(wallet.address, 'pending').then((nonce) => {

                    let privateKey = Buffer.from(wallet.private_key.substring(2, 66), 'hex');

                    let tx = new Tx({
                        nonce: web3.utils.numberToHex(nonce),
                        gasPrice: web3.utils.numberToHex(web3.utils.toWei(gasGweiPrice.toString(), 'Gwei')),
                        gasLimit: web3.utils.numberToHex(gasLimit),
                        to: wallet.contract,
                        value: web3.utils.numberToHex(0),
                        data: tokenData
                    }, {'common': MATIC_FORK});

                    tx.sign(privateKey);

                    let transaction = web3.eth.sendSignedTransaction('0x' + tx.serialize().toString('hex'))

                    transaction.on('confirmation', confirmation).catch(callback);

                }).catch((error) => {
                    throw error;
                });

            });

        }).catch((error) => {
            console.log(error);
            throw error;
        });

    }).catch((error) => {
        console.log(error);
        throw error;
    });
};

exports.signMainWalletMatic20Transaction = signMainWalletMatic20Transaction;

const sendFeeAndWithdrawToMain = (deposit, callback, confirmation) => {

    let tokenContract = createTokenContract(deposit.contract);

    let tokenData = tokenContract.methods.transfer(deposit.wallet, deposit.wei.toString()).encodeABI();
    console.log(deposit);

    web3.eth.estimateGas({from: deposit.address, to: deposit.contract, data: tokenData}).then((gasLimitErc) => {

        console.log("Gas limit is " + gasLimitErc);

        ethGas((gasGweiPrice) => {

            let gasPrice = web3.utils.toWei(gasGweiPrice, 'Gwei');

            let fee = bigInt(gasLimitErc).multiply(gasPrice).toString();

            let privateKey = Buffer.from(deposit.private_key.substring(2, 66), 'hex');

            web3.eth.getTransactionCount(deposit.wallet, 'pending').then((txCount) => {

                let gasLimit = 21000;

                let tx = new Tx({
                    gasPrice: web3.utils.numberToHex(gasPrice),
                    gasLimit: web3.utils.numberToHex(gasLimit),
                    nonce: web3.utils.numberToHex(txCount),
                    to: deposit.address,
                    value: web3.utils.numberToHex(fee)
                }, {'common': MATIC_FORK});

                tx.sign(privateKey);

                let transaction = web3.eth.sendSignedTransaction('0x' + tx.serialize().toString('hex'))

                transaction.on('receipt', (receipt) => {
                    console.log(receipt);
                }).catch((error) => {


                    global.database.query({
                        text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                        values: ['fee_error', deposit.id],
                    }).then(data => {
                        console.log('Matic20 Deposit Transfer Failed with id ' + deposit.id);
                    });

                    console.log("Matic Balance transfer from main wallet ");
                    console.log(error);

                });

                // Fee amount was sent and start sending Matic20 amount to main wallet after first confirmation
                transaction.on('confirmation', (confirms, receipt) => {

                    if (confirms == 1 && receipt.transactionHash) {

                        console.log("Fee paid for " + deposit.deposit_id);

                        let privateKey = Buffer.from(deposit.address_private_key.substring(2, 66), 'hex');

                        web3.eth.getTransactionCount(deposit.address, 'pending').then((txCount) => {

                            let tx = new Tx({
                                nonce: web3.utils.numberToHex(txCount),
                                gasPrice: web3.utils.numberToHex(web3.utils.toWei(gasGweiPrice.toString(), 'Gwei')),
                                gasLimit: web3.utils.numberToHex(gasLimitErc),
                                to: deposit.contract,
                                value: web3.utils.numberToHex(0),
                                data: tokenData
                            }, {'common': MATIC_FORK});

                            tx.sign(privateKey);

                            // Send Matic20 and wait for confirmation

                            let ercTransaction = web3.eth.sendSignedTransaction('0x' + tx.serialize().toString('hex'))

                            ercTransaction.on('confirmation', confirmation).catch(callback);

                        }).catch((error) => {
                            throw error;
                        });
                    }

                }).catch(callback);

            }).catch((error) => {
                throw error;
            });
        });
    });
};

exports.sendFeeAndWithdrawToMain = sendFeeAndWithdrawToMain;

const ethGas = (callback) => {
    web3.eth.getGasPrice()
        .then(price => callback(Math.round(parseInt(web3.utils.fromWei(price, 'Gwei')) + 10).toString())).catch(error => {
        console.log(error);
        throw error;
    }).catch((error) => {
        console.log(error);
        throw error;
    });
}

exports.ethGas = ethGas;

// Get the latest block
const getLatestBlock = (callback) => {
    web3.eth.getBlockNumber().then(callback).catch(console.log);
}
exports.getLatestBlock = getLatestBlock;

// Get account balance
const getAccountBalance = (address, contract, callback) => {
    if (contract != '') {

        let tokenContract = createTokenContract(contract);

        tokenContract.methods.balanceOf(address).call((error, balance) => {
            if (error) {
                console.log("ERROR HERE ON CONTRACT");
                console.log(contract, error);
            }

            tokenContract.methods.decimals().call((error, decimals) => {
                callback(balance, decimals);
            });
        });
    } else {
        web3.eth.getBalance(address).then(callback);
    }
}
exports.getAccountBalance = getAccountBalance;

const createTokenContract = (address) => {
    return new web3.eth.Contract(require('../../contract/abi'), address);
};

exports.createTokenContract = createTokenContract;

const toFixed = (x) => {
    if (Math.abs(x) < 1.0) {
        var e = parseInt(x.toString().split('e-')[1]);
        if (e) {
            x *= Math.pow(10, e - 1);
            x = '0.' + (new Array(e)).join('0') + x.toString().substring(2);
        }
    } else {
        var e = parseInt(x.toString().split('+')[1]);
        if (e > 20) {
            e -= 20;
            x /= Math.pow(10, e);
            x += (new Array(e + 1)).join('0');
        }
    }
    return x;
}

exports.toFixed = toFixed;

const weiToNumber = (amount, decimals) => {

    let bnAmount = new BN(amount);
    let decimal = new BN('10').pow(new BN(decimals));

    return toFixed(bnAmount / decimal);
}

exports.weiToNumber = weiToNumber;

const numberToWei = (amount, decimals) => {

    console.log("Decimals: " + (parseInt(decimals)+1));

    let formatted = Number(amount).toFixed(parseInt(decimals)+1).match(new RegExp('^-?\\d+(?:\.\\d{0,' + (decimals || -1) + '})?'))[0];

    let result = toFixed(formatted * Math.pow(10, decimals));

    if(decimals < 18) {
        console.log("Returned:" + result);
        return parseInt(result);
    }

    console.log("Returned 2:" + result);

    return result;
}

exports.numberToWei = numberToWei;

// Create a new wallet address
const walletCreate = () => {
    return web3.eth.accounts.create();
};
exports.walletCreate = walletCreate;

// Get transaction details by hash
const getTransaction = (txHash, callback) => {
    web3.eth.getTransaction(txHash).then(callback).catch((error) => {
        console.log(txHash);
    });
}
exports.getTransaction = getTransaction;
