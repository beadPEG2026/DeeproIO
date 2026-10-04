/**
 * Binance Smart Chain (BSC) Bridge Methods Module
 *
 * This module provides core functionality for interacting with the BSC blockchain,
 * including wallet creation, BNB and BEP-20 token transfers, balance queries, and
 * transaction signing.
 *
 * Uses custom chain configuration for BSC as it's an EVM-compatible chain
 * forked from Ethereum with different network/chain IDs.
 *
 * @module bnb/bridge/methods
 */

// Load libraries
require('../../bnb-config');
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
 * BSC Mainnet chain configuration for transaction signing.
 * Required because BSC uses different chain/network IDs than Ethereum mainnet.
 *
 * Network ID: 56 (BSC Mainnet)
 * Chain ID: 56 (BSC Mainnet)
 *
 * For testnet, use networkId/chainId: 97
 */
let BSC_FORK = Common.forCustomChain(
    'mainnet',
    {
        name: 'Binance Smart Chain Mainnet',
        networkId: 56,
        chainId: 56,
        url: 'https://bsc-dataseed.binance.org'
    },
    'istanbul',
);

// Initialize Web3 instance with configured BSC node
let web3 = new Web3(new web3Provider(global.geth, global.web3config));

// Export BigNumber utility for precise arithmetic operations
const BN = web3.utils.BN;
exports.web3 = web3;

/**
 * Transfers BNB from a user's wallet to the main system wallet.
 * This is typically called when a deposit is detected and needs to be consolidated.
 *
 * @param {Object} wallet - Wallet configuration object
 * @param {string} wallet.address - Source wallet address
 * @param {string} wallet.address_private_key - Private key for signing the transaction
 * @param {string} wallet.to - Destination (main) wallet address
 * @param {number} wallet.amount - Amount of BNB to transfer
 * @param {boolean} wallet.fee - If true, deducts gas fee from the transfer amount
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback, receives (confirmationNumber, receipt)
 * @returns {void}
 */
const walletTransferToMainWallet = (wallet, callback, confirmation) => {
    return signMainWalletBnbTransaction(wallet, callback, confirmation);
}
exports.walletTransferToMainWallet = walletTransferToMainWallet;

/**
 * Transfers BEP-20 tokens from a user's wallet to the main system wallet.
 * This handles the two-step process of sending gas fee first, then transferring tokens.
 *
 * @param {Object} wallet - Wallet and token configuration object
 * @param {string} wallet.address - Source wallet address containing the tokens
 * @param {string} wallet.contract - BEP-20 token contract address
 * @param {string} wallet.wallet - Main system wallet address
 * @param {string} wallet.wei - Token amount in smallest unit
 * @param {string} wallet.private_key - Private key of main wallet (for gas fee transfer)
 * @param {string} wallet.address_private_key - Private key of source wallet (for token transfer)
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback for the token transfer
 * @returns {void}
 */
const walletTransferBepToMainWallet = (wallet, callback, confirmation) => {
    return sendFeeAndWithdrawToMain(wallet, callback, confirmation);
}

exports.walletTransferBepToMainWallet = walletTransferBepToMainWallet;

/**
 * Withdraws funds from the main wallet to an external address.
 * Automatically determines whether to use BNB or BEP-20 transfer based on contract presence.
 *
 * @param {Object} wallet - Withdrawal configuration object
 * @param {string} wallet.address - Source (main) wallet address
 * @param {string} wallet.private_key - Private key for signing
 * @param {string} wallet.to - Destination wallet address
 * @param {number} wallet.amount - Amount to withdraw
 * @param {string} [wallet.contract] - Optional BEP-20 contract address; if present, uses token transfer
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback function
 * @returns {void}
 */
const walletWithdrawFromMainWallet = (wallet, callback, confirmation) => {
    if (wallet.contract) {
        return signMainWalletBepTransaction(wallet, callback, confirmation);
    } else {
        return signMainWalletBnbTransaction(wallet, callback, confirmation);
    }
}
exports.walletWithdrawFromMainWallet = walletWithdrawFromMainWallet;

/**
 * Signs and broadcasts a BNB transfer transaction.
 * Handles gas price estimation, nonce management, and transaction signing
 * using BSC-specific chain configuration.
 *
 * @param {Object} wallet - Transaction configuration object
 * @param {string} wallet.address - Sender wallet address
 * @param {string} wallet.address_private_key - Private key for signing (with 0x prefix)
 * @param {string} wallet.to - Recipient wallet address
 * @param {number} wallet.amount - Amount of BNB to send
 * @param {boolean} [wallet.fee] - If true, deducts gas fee from amount (for sweeping entire balance)
 * @param {Function} callback - Error callback
 * @param {Function} confirmation - Success callback, receives (confirmationNumber, receipt)
 * @returns {void}
 */
const signMainWalletBnbTransaction = (wallet, callback, confirmation) => {

    ethGas((gasGweiPrice) => {
        try {
            console.log("Current eth gas costs " + gasGweiPrice);

            let gasLimit = 21000;
            let gasPrice = web3.utils.toWei(gasGweiPrice, 'Gwei');

            let amount = web3.utils.toWei(wallet.amount.toString());
            console.log("Origin amount: " + wallet.amount.toString());
            console.log("Amount in Wei:" + amount);
            console.log("Gas Price" + gasPrice);

            if (wallet.fee) {

                let gasFee = gasLimit * gasPrice;

                console.log("Gas Fee" + gasFee);

                amount = amount - gasFee;

                console.log("Transfer amount after fee: " + amount);

                if (amount < 0) {
                    console.log('Insufficient funds');
                    return;
                }
            }

            let privateKey = Buffer.from(wallet.address_private_key.substring(2, 66), 'hex');

            web3.eth.getTransactionCount(wallet.address, 'pending').then((txCount) => {

                let tx = new Tx({
                    nonce: web3.utils.numberToHex(txCount),
                    gasPrice: web3.utils.numberToHex(gasPrice),
                    gasLimit: web3.utils.numberToHex(gasLimit),
                    to: wallet.to,
                    value: web3.utils.numberToHex(amount)
                }, {'common': BSC_FORK});

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
exports.signMainWalletBnbTransaction = signMainWalletBnbTransaction;


/**
 * Signs and broadcasts a BEP-20 token transfer transaction.
 * Queries token decimals, encodes transfer data, estimates gas, and sends the transaction.
 * Uses BSC-specific chain configuration for proper transaction signing.
 *
 * @param {Object} wallet - Token transfer configuration object
 * @param {string} wallet.address - Sender wallet address (must have tokens and BNB for gas)
 * @param {string} wallet.private_key - Private key for signing (with 0x prefix)
 * @param {string} wallet.to - Recipient wallet address
 * @param {number} wallet.amount - Amount of tokens to send (in token units, not wei)
 * @param {string} wallet.contract - BEP-20 token contract address
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Success callback, receives (confirmationNumber, receipt)
 * @returns {void}
 */
const signMainWalletBepTransaction = (wallet, callback, confirmation) => {

    // Create contract instance to interact with the token
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
                    }, {'common': BSC_FORK});

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

exports.signMainWalletBepTransaction = signMainWalletBepTransaction;

/**
 * Sends gas fee to a deposit address and then transfers BEP-20 tokens to the main wallet.
 * This is a two-step process required because deposit addresses often don't have BNB for gas.
 *
 * Step 1: Send BNB from main wallet to deposit address to cover gas fees
 * Step 2: After confirmation, transfer tokens from deposit address to main wallet
 *
 * @param {Object} deposit - Deposit configuration object
 * @param {string} deposit.address - Deposit address containing the tokens
 * @param {string} deposit.wallet - Main system wallet address
 * @param {string} deposit.contract - BEP-20 token contract address
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

        web3.eth.estimateGas({from: deposit.address, to: deposit.contract, data: tokenData}).then((gasLimitBep) => {

            console.log("Gas limit is " + gasLimitBep);

            ethGas((gasGweiPrice) => {

                let gasPrice = web3.utils.toWei(gasGweiPrice, 'Gwei');

                let fee = bigInt(gasLimitBep).multiply(gasPrice).toString();

                let privateKey = Buffer.from(deposit.private_key.substring(2, 66), 'hex');

                web3.eth.getTransactionCount(deposit.wallet, 'pending').then((txCount) => {

                    let gasLimit = 21000;

                    let tx = new Tx({
                        gasPrice: web3.utils.numberToHex(gasPrice),
                        gasLimit: web3.utils.numberToHex(gasLimit),
                        nonce: web3.utils.numberToHex(txCount),
                        to: deposit.address,
                        value: web3.utils.numberToHex(fee)
                    }, {'common': BSC_FORK});

                    tx.sign(privateKey);

                    let transaction = web3.eth.sendSignedTransaction('0x' + tx.serialize().toString('hex'))

                    transaction.on('receipt', (receipt) => {
                        console.log(receipt);
                    }).catch((error) => {

                        if(is_uuid(deposit.id.toString())) {

                            global.database.query({
                                text: 'UPDATE deposits SET wallet_transfer_status = $1 WHERE id = $2',
                                values: ['fee_error', deposit.id],
                            }).then(data => {
                                console.log('Erc Deposit Transfer Failed with id ' + deposit.id);
                            });

                            console.log("BNB Balance transfer from main wallet ");
                            console.log(error);

                        }

                    });

                    // Fee amount was sent and start sending Bep amount to main wallet after first confirmation
                    transaction.on('confirmation', (confirms, receipt) => {

                        if (confirms == 1 && receipt.transactionHash) {

                            console.log("Fee paid for " + deposit.deposit_id);

                            let privateKey = Buffer.from(deposit.address_private_key.substring(2, 66), 'hex');

                            web3.eth.getTransactionCount(deposit.address, 'pending').then((txCount) => {

                                let tx = new Tx({
                                    nonce: web3.utils.numberToHex(txCount),
                                    gasPrice: web3.utils.numberToHex(web3.utils.toWei(gasGweiPrice.toString(), 'Gwei')),
                                    gasLimit: web3.utils.numberToHex(gasLimitBep),
                                    to: deposit.contract,
                                    value: web3.utils.numberToHex(0),
                                    data: tokenData
                                }, {'common': BSC_FORK});

                                tx.sign(privateKey);

                                // Send Bep and wait for confirmation

                                let bepTransaction = web3.eth.sendSignedTransaction('0x' + tx.serialize().toString('hex'))

                                bepTransaction.on('confirmation', confirmation).catch(callback);

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
 * Fetches current gas price from the BSC network and adds a buffer for faster confirmation.
 * Adds 20 Gwei to the network price to prioritize transaction processing.
 * BSC typically has lower gas costs than Ethereum but faster block times.
 *
 * @param {Function} callback - Receives the recommended gas price in Gwei as a string
 * @returns {void}
 */
const ethGas = (callback) => {
    web3.eth.getGasPrice()
        .then(price => callback(Math.round(parseInt(web3.utils.fromWei(price, 'Gwei')) + 20).toString())).catch(error => {
        console.log(error);
        throw error;
    }).catch((error) => {
        console.log(error);
        throw error;
    });
}

exports.ethGas = ethGas;

/**
 * Retrieves the latest block number from the BSC network.
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
 * Gets the balance of an address for BNB or BEP-20 tokens.
 * If contract address is provided, queries token balance; otherwise queries BNB balance.
 *
 * @param {string} address - Wallet address to check balance for
 * @param {string} contract - BEP-20 contract address (empty string for BNB balance)
 * @param {Function} callback - Receives (balance, decimals) for tokens or just (balance) for BNB
 * @returns {void}
 */
const getAccountBalance = (address, contract, callback) => {
    if (contract != '') {
        // Query BEP-20 token balance
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
        // Query native BNB balance
        web3.eth.getBalance(address).then(callback);
    }
}
exports.getAccountBalance = getAccountBalance;

/**
 * Creates a Web3 contract instance for a BEP-20 token.
 * Uses the standard BEP-20 ABI (same as ERC-20) for token interactions.
 *
 * @param {string} address - BEP-20 token contract address
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

/**
 * Creates a new BSC wallet with a randomly generated private key.
 * Returns the wallet object containing address and private key.
 * BSC uses the same address format as Ethereum.
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
