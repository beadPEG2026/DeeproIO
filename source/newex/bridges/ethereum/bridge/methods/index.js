/**
 * Ethereum Bridge Methods Module
 *
 * This module provides core functionality for interacting with the Ethereum blockchain,
 * including wallet creation, ETH and ERC-20 token transfers, balance queries, and
 * transaction signing.
 *
 * @module ethereum/bridge/methods
 */

// Load libraries
require('../../eth-config');
const Web3 = require('web3');
const web3Provider = require('web3-providers-http');
const Tx = require('ethereumjs-tx').Transaction;
const bigInt = require("big-integer");
let mysql = require('mysql');

const { Client } = require('pg');

// Initialize PostgreSQL database connection for deposit/withdrawal tracking
require('../../../database').connect();

// Initialize Web3 instance with configured Ethereum node
let web3 = new Web3(new web3Provider(global.geth, global.web3config));

// Export BigNumber utility for precise arithmetic operations
const BN = web3.utils.BN;
exports.web3 = web3;

/**
 * Transfers ETH from a user's wallet to the main system wallet.
 * This is typically called when a deposit is detected and needs to be consolidated.
 *
 * @param {Object} wallet - Wallet configuration object
 * @param {string} wallet.address - Source wallet address
 * @param {string} wallet.private_key - Private key for signing the transaction
 * @param {string} wallet.to - Destination (main) wallet address
 * @param {number} wallet.amount - Amount of ETH to transfer
 * @param {boolean} wallet.fee - If true, deducts gas fee from the transfer amount
 * @param {Function} callback - Error callback function, receives Error instance on failure
 * @param {Function} confirmation - Confirmation callback, receives (confirmationNumber, receipt)
 * @returns {void}
 */
const walletTransferToMainWallet = (wallet, callback, confirmation) => {
    return signMainWalletEthTransaction(wallet, callback, confirmation);
}
exports.walletTransferToMainWallet = walletTransferToMainWallet;

/**
 * Transfers ERC-20 tokens from a user's wallet to the main system wallet.
 * This handles the two-step process of sending gas fee first, then transferring tokens.
 *
 * @param {Object} wallet - Wallet and token configuration object
 * @param {string} wallet.address - Source wallet address containing the tokens
 * @param {string} wallet.contract - ERC-20 token contract address
 * @param {string} wallet.wallet - Main system wallet address
 * @param {string} wallet.wei - Token amount in smallest unit (wei equivalent)
 * @param {string} wallet.private_key - Private key of main wallet (for gas fee transfer)
 * @param {string} wallet.address_private_key - Private key of source wallet (for token transfer)
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback for the token transfer
 * @returns {void}
 */
const walletTransferErcToMainWallet = (wallet, callback, confirmation) => {
    return sendFeeAndWithdrawToMain(wallet, callback, confirmation);
}

exports.walletTransferErcToMainWallet = walletTransferErcToMainWallet;

/**
 * Withdraws funds from the main wallet to an external address.
 * Automatically determines whether to use ETH or ERC-20 transfer based on contract presence.
 *
 * @param {Object} wallet - Withdrawal configuration object
 * @param {string} wallet.address - Source (main) wallet address
 * @param {string} wallet.private_key - Private key for signing
 * @param {string} wallet.to - Destination wallet address
 * @param {number} wallet.amount - Amount to withdraw
 * @param {string} [wallet.contract] - Optional ERC-20 contract address; if present, uses token transfer
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback function
 * @returns {void}
 */
const walletWithdrawFromMainWallet = (wallet, callback, confirmation) => {
    if (wallet.contract) {
        return signMainWalletErcTransaction(wallet, callback, confirmation);
    } else {
        return signMainWalletEthTransaction(wallet, callback, confirmation);
    }
}
exports.walletWithdrawFromMainWallet = walletWithdrawFromMainWallet;

/**
 * Signs and broadcasts an ETH transfer transaction.
 * Handles gas price estimation, nonce management, and transaction signing.
 *
 * @param {Object} wallet - Transaction configuration object
 * @param {string} wallet.address - Sender wallet address
 * @param {string} wallet.private_key - Private key for signing (with 0x prefix)
 * @param {string} wallet.to - Recipient wallet address
 * @param {number} wallet.amount - Amount of ETH to send
 * @param {boolean} [wallet.fee] - If true, deducts gas fee from amount (for sweeping entire balance)
 * @param {Function} callback - Error callback, called with Error instance on failure
 * @param {Function} confirmation - Success callback, receives (confirmationNumber, receipt)
 * @returns {void}
 */
const signMainWalletEthTransaction = (wallet, callback, confirmation) => {

    ethGas((gasGweiPrice) => {
        try {
            console.log("Current eth gas costs " + gasGweiPrice);
            console.log(wallet);
            // Standard gas limit for simple ETH transfers
            let gasLimit = 21000;
            let gasPrice = web3.utils.toWei(gasGweiPrice, 'Gwei');

            let amount = web3.utils.toWei(wallet.amount.toString());

            // If fee flag is set, deduct gas cost from transfer amount (sweep operation)
            if (wallet.fee) {

                let gasFee = gasLimit * gasPrice;
                amount = amount - gasFee;

                if (amount < 0) {
                    console.log('Insufficient funds');
                    return;
                }
            }

            // Convert private key from hex string to Buffer for signing
            let privateKey = Buffer.from(wallet.private_key.substring(2, 66), 'hex');

            // Get current transaction count for nonce
            web3.eth.getTransactionCount(wallet.address, 'pending').then((txCount) => {

                // Build raw transaction object
                let tx = new Tx({
                    nonce: web3.utils.numberToHex(txCount),
                    gasPrice: web3.utils.numberToHex(gasPrice),
                    gasLimit: web3.utils.numberToHex(gasLimit),
                    to: wallet.to,
                    value: web3.utils.numberToHex(amount)
                }, {'chain': global.geth_env});

                // Sign transaction with private key
                tx.sign(privateKey);

                // Broadcast signed transaction to the network
                let transaction = web3.eth.sendSignedTransaction('0x' + tx.serialize().toString('hex'))

                // Register confirmation handler
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
exports.signMainWalletEthTransaction = signMainWalletEthTransaction;


/**
 * Signs and broadcasts an ERC-20 token transfer transaction.
 * Queries token decimals, encodes transfer data, estimates gas, and sends the transaction.
 *
 * @param {Object} wallet - Token transfer configuration object
 * @param {string} wallet.address - Sender wallet address (must have tokens and ETH for gas)
 * @param {string} wallet.private_key - Private key for signing (with 0x prefix)
 * @param {string} wallet.to - Recipient wallet address
 * @param {number} wallet.amount - Amount of tokens to send (in token units, not wei)
 * @param {string} wallet.contract - ERC-20 token contract address
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Success callback, receives (confirmationNumber, receipt)
 * @returns {void}
 */
const signMainWalletErcTransaction = (wallet, callback, confirmation) => {

    // Create contract instance to interact with the token
    let tokenContract = createTokenContract(wallet.contract);

    // Get token decimals to properly format the amount
    tokenContract.methods.decimals().call((error, decimals) => {

        // Convert human-readable amount to token's smallest unit
        let amount = numberToWei(wallet.amount, decimals);

        // Encode the transfer function call as ABI data
        let tokenData = tokenContract.methods.transfer(wallet.to, amount.toString()).encodeABI();

        // Estimate gas required for the token transfer
        web3.eth.estimateGas({from: wallet.address, to: wallet.contract, data: tokenData}).then((gasLimit) => {

            ethGas((gasGweiPrice) => {

                // Get nonce for transaction ordering
                web3.eth.getTransactionCount(wallet.address, 'pending').then((nonce) => {

                    let privateKey = Buffer.from(wallet.private_key.substring(2, 66), 'hex');

                    // Build transaction to call token contract's transfer function
                    let tx = new Tx({
                        nonce: web3.utils.numberToHex(nonce),
                        gasPrice: web3.utils.numberToHex(web3.utils.toWei(gasGweiPrice.toString(), 'Gwei')),
                        gasLimit: web3.utils.numberToHex(gasLimit),
                        to: wallet.contract,  // Send to contract address, not recipient
                        value: web3.utils.numberToHex(0),  // No ETH value, just token transfer
                        data: tokenData  // Encoded transfer call
                    }, {'chain': global.geth_env});

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

exports.signMainWalletErcTransaction = signMainWalletErcTransaction;

/**
 * Sends gas fee to a deposit address and then transfers ERC-20 tokens to the main wallet.
 * This is a two-step process required because deposit addresses often don't have ETH for gas.
 *
 * Step 1: Send ETH from main wallet to deposit address to cover gas fees
 * Step 2: After confirmation, transfer tokens from deposit address to main wallet
 *
 * @param {Object} deposit - Deposit configuration object
 * @param {string} deposit.address - Deposit address containing the tokens
 * @param {string} deposit.wallet - Main system wallet address
 * @param {string} deposit.contract - ERC-20 token contract address
 * @param {string} deposit.wei - Token amount in smallest unit
 * @param {string} deposit.private_key - Private key of main wallet (for gas fee transfer)
 * @param {string} deposit.address_private_key - Private key of deposit address (for token transfer)
 * @param {number} deposit.id - Deposit ID for database updates
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Success callback for the final token transfer
 * @returns {void}
 */
const sendFeeAndWithdrawToMain = (deposit, callback, confirmation) => {

    // Create contract instance for token interaction
    let tokenContract = createTokenContract(deposit.contract);

    tokenContract.methods.decimals().call((error, decimals) => {

        // Encode the token transfer call
        let tokenData = tokenContract.methods.transfer(deposit.wallet, numberToWei(deposit.amount, decimals).toString()).encodeABI();

        console.log(deposit);

        // Estimate gas needed for the token transfer
        web3.eth.estimateGas({from: deposit.address, to: deposit.contract, data: tokenData}).then((gasLimitErc) => {

            console.log("Gas limit is " + gasLimitErc);

            ethGas((gasGweiPrice) => {

                let gasPrice = web3.utils.toWei(gasGweiPrice, 'Gwei');

                // Calculate total fee needed for the token transfer
                let fee = bigInt(gasLimitErc).multiply(gasPrice).toString();

                // Use main wallet's private key to send gas fee
                let privateKey = Buffer.from(deposit.private_key.substring(2, 66), 'hex');

                web3.eth.getTransactionCount(deposit.wallet, 'pending').then((txCount) => {

                    let gasLimit = 21000;  // Standard gas for ETH transfer

                    // Step 1: Send ETH (gas fee) from main wallet to deposit address
                    let tx = new Tx({
                        gasPrice: web3.utils.numberToHex(gasPrice),
                        gasLimit: web3.utils.numberToHex(gasLimit),
                        nonce: web3.utils.numberToHex(txCount),
                        to: deposit.address,
                        value: web3.utils.numberToHex(fee)
                    }, {'chain': global.geth_env});

                    tx.sign(privateKey);

                    let transaction = web3.eth.sendSignedTransaction('0x' + tx.serialize().toString('hex'))

                    transaction.on('receipt', (receipt) => {
                        console.log(receipt);
                    }).catch((error) => {

                            console.log(error);

                            if(is_uuid(deposit.id.toString())) {

                                // Update database on fee transfer failure
                                global.database.query({
                                    text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                                    values: ['fee_error', deposit.id],
                                }).then(data => {
                                    console.log('Erc Deposit Transfer Failed with id ' + deposit.id);
                                });

                                console.log("ETH Balance transfer from main wallet ");
                                console.log(error);

                            } else {
                                console.log('Not UUID')
                            }

                    });

                    // Step 2: After fee is confirmed, send tokens to main wallet
                    transaction.on('confirmation', (confirms, receipt) => {

                        if (confirms == 1 && receipt.transactionHash) {

                            console.log("Fee paid for " + deposit.deposit_id);

                            // Now use deposit address's private key to transfer tokens
                            let privateKey = Buffer.from(deposit.address_private_key.substring(2, 66), 'hex');

                            web3.eth.getTransactionCount(deposit.address, 'pending').then((txCount) => {

                                // Build token transfer transaction
                                let tx = new Tx({
                                    nonce: web3.utils.numberToHex(txCount),
                                    gasPrice: web3.utils.numberToHex(web3.utils.toWei(gasGweiPrice.toString(), 'Gwei')),
                                    gasLimit: web3.utils.numberToHex(gasLimitErc),
                                    to: deposit.contract,
                                    value: web3.utils.numberToHex(0),
                                    data: tokenData
                                }, {
                                    'chain': global.geth_env
                                });

                                tx.sign(privateKey);

                                // Broadcast token transfer transaction
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
    });
};

exports.sendFeeAndWithdrawToMain = sendFeeAndWithdrawToMain;

/**
 * Fetches current gas price from the network and adds a buffer for faster confirmation.
 * Adds 10 Gwei to the network price to prioritize transaction processing.
 *
 * @param {Function} callback - Receives the recommended gas price in Gwei as a string
 * @returns {void}
 */
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

/**
 * Retrieves the latest block number from the Ethereum network.
 * Useful for monitoring chain synchronization and tracking confirmations.
 *
 * @param {Function} callback - Receives the current block number
 * @returns {void}
 */
const getLatestBlock = (callback) => {
    web3.eth.getBlockNumber().then(callback).catch(console.log);
}
exports.getLatestBlock = getLatestBlock;

/**
 * Gets the balance of an address for ETH or ERC-20 tokens.
 * If contract address is provided, queries token balance; otherwise queries ETH balance.
 *
 * @param {string} address - Wallet address to check balance for
 * @param {string} contract - ERC-20 contract address (empty string for ETH balance)
 * @param {Function} callback - Receives (balance, decimals) for tokens or just (balance) for ETH
 * @returns {void}
 */
const getAccountBalance = (address, contract, callback) => {
    if (contract != '') {
        // Query ERC-20 token balance
        let tokenContract = createTokenContract(contract);

        tokenContract.methods.balanceOf(address).call((error, balance) => {
            if (error) {
                console.log("ERROR HERE ON CONTRACT");
                console.log(contract, error);
            }

            // Also fetch decimals for proper formatting
            tokenContract.methods.decimals().call((error, decimals) => {
                callback(balance, decimals);
            });
        });
    } else {
        // Query native ETH balance
        web3.eth.getBalance(address).then(callback);
    }
}
exports.getAccountBalance = getAccountBalance;

/**
 * Creates a Web3 contract instance for an ERC-20 token.
 * Uses the standard ERC-20 ABI for token interactions.
 *
 * @param {string} address - ERC-20 token contract address
 * @returns {Object} Web3 Contract instance
 */
const createTokenContract = (address) => {
    return new web3.eth.Contract(require('../../contract/abi'), address);
};

exports.createTokenContract = createTokenContract;

/**
 * Converts a number to fixed-point notation without scientific notation.
 * Handles both very small numbers (e-notation) and very large numbers (e+ notation).
 *
 * @param {number} x - Number to convert
 * @returns {string|number} Number in fixed-point notation
 */
const toFixed = (x) => {
    if (Math.abs(x) < 1.0) {
        // Handle very small numbers (e.g., 1e-18)
        var e = parseInt(x.toString().split('e-')[1]);
        if (e) {
            x *= Math.pow(10, e - 1);
            x = '0.' + (new Array(e)).join('0') + x.toString().substring(2);
        }
    } else {
        // Handle very large numbers (e.g., 1e+20)
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

/**
 * Converts a value from wei (smallest unit) to a human-readable number.
 * Uses BigNumber for precision with large values.
 *
 * @param {string|number} amount - Amount in wei/smallest unit
 * @param {number} decimals - Number of decimal places for the token
 * @returns {string|number} Human-readable amount
 */
const weiToNumber = (amount, decimals) => {

    let bnAmount = new BN(amount);
    let decimal = new BN('10').pow(new BN(decimals));

    return toFixed(bnAmount / decimal);
}

exports.weiToNumber = weiToNumber;

/**
 * Converts a human-readable number to wei (smallest unit).
 * Handles decimal precision based on token's decimal places.
 *
 * @param {number} amount - Human-readable amount
 * @param {number} decimals - Number of decimal places for the token
 * @returns {number|string} Amount in wei/smallest unit
 */
const numberToWei = (amount, decimals) => {

    console.log("Decimals: " + (parseInt(decimals)+1));

    // Format to proper decimal places and extract the numeric portion
    let formatted = Number(amount).toFixed(parseInt(decimals)+1).match(new RegExp('^-?\\d+(?:\.\\d{0,' + (decimals || -1) + '})?'))[0];

    // Multiply by 10^decimals to get wei value
    let result = toFixed(formatted * Math.pow(10, decimals));

    // For tokens with fewer than 18 decimals, return as integer
    if(decimals < 18) {
        console.log("Returned:" + result);
        return parseInt(result);
    }

    console.log("Returned 2:" + result);

    return result;
}

exports.numberToWei = numberToWei;

/**
 * Creates a new Ethereum wallet with a random private key.
 * Returns the wallet object containing address and private key.
 *
 * @returns {Object} Wallet object with address and privateKey properties
 */
const walletCreate = () => {
    return web3.eth.accounts.create();
};
exports.walletCreate = walletCreate;

/**
 * Retrieves transaction details by transaction hash.
 * Useful for checking transaction status and details.
 *
 * @param {string} txHash - Transaction hash to look up
 * @param {Function} callback - Receives the transaction object
 * @returns {void}
 */
const getTransaction = (txHash, callback) => {
    web3.eth.getTransaction(txHash).then(callback).catch((error) => {
        console.log(txHash);
    });
}
exports.getTransaction = getTransaction;

function is_uuid(value) {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}
