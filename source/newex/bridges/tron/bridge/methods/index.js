/**
 * TRON Bridge Methods Module
 * 
 * This module provides core functionality for interacting with the TRON blockchain,
 * including wallet creation, TRX and TRC-20 token transfers, balance queries, and
 * transaction signing.
 * 
 * TRON uses a different architecture than Ethereum-based chains:
 * - Uses TronWeb library instead of Web3
 * - Addresses are base58-encoded starting with 'T'
 * - Uses bandwidth and energy for transaction fees
 * - TRX has 6 decimals (not 18 like ETH)
 * 
 * @module tron/bridge/methods
 */

// Load libraries
require('../../tron-config');
let mysql = require('mysql');
const TronWeb = require('tronweb')
const bigInt = require("big-integer");

const { Client } = require('pg');
const ethers = require("ethers");


// Initialize PostgreSQL database connection for deposit/withdrawal tracking
require('../../../database').connect();

let web3;
let baseUrl;

// Initialize TronWeb instance with TronGrid API
try {

    // TronGrid is the official TRON API provider
    baseUrl = 'https://api.trongrid.io';

    const apiKey=String(global.TRONGRID_API_KEY || '').trim();
    if (!/^[\x21-\x7e]+$/.test(apiKey)) throw Error('TRONGRID_KEY_MISSING_OR_INVALID');
    const governance=require('../../rpc-governance');
    const governor=governance.budget(global.database,apiKey,Number(process.env.DEPOSIT_TRON_REQUEST_INTERVAL||1)*1000);
    const HttpProvider = TronWeb.providers.HttpProvider;
    const headers={"TRON-PRO-API-KEY":apiKey};
    const providers=[0,1,2].map(()=>new HttpProvider(baseUrl,20000,false,false,headers));
    // Wrap before construction: TronWeb also probes the full node during startup.
    // setHeader() must not be used afterwards: TronWeb replaces all providers.
    governance.wrapProviders(providers,governor);
    web3 = new TronWeb(...providers);
    global.tronRpcGovernance={shared_budget:true,key_fingerprint:governor.fingerprint.slice(0,12)};


} catch (error) {
    console.error('TRON_BRIDGE_INITIALIZATION_FAILED');
    process.exit(1);
}

exports.web3 = web3;
exports.baseUrl = baseUrl;

/**
 * Transfers TRX from a user's wallet to the main system wallet.
 * This is typically called when a deposit is detected and needs to be consolidated.
 * 
 * @param {Object} wallet - Wallet configuration object
 * @param {string} wallet.address - Source wallet address (T...)
 * @param {string} wallet.private_key - Private key for signing the transaction
 * @param {string} wallet.to - Destination (main) wallet address
 * @param {number} wallet.amount - Amount of TRX to transfer
 * @param {number} wallet.amount_deducted - Amount after bandwidth fee deduction (if applicable)
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback, receives transaction data
 * @returns {void}
 */
const walletTransferToMainWallet = (wallet, callback, confirmation) => {
    return signMainWalletTrxTransaction(wallet, callback, confirmation);
}
exports.walletTransferToMainWallet = walletTransferToMainWallet;

/**
 * Transfers TRC-20 tokens from a user's wallet to the main system wallet.
 * This handles the process of estimating energy, sending TRX for fees, then transferring tokens.
 * 
 * @param {Object} wallet - Wallet and token configuration object
 * @param {string} wallet.address - Source wallet address containing the tokens
 * @param {string} wallet.contract - TRC-20 token contract address
 * @param {string} wallet.wallet - Main system wallet address
 * @param {string} wallet.wei - Token amount in smallest unit
 * @param {string} wallet.private_key - Private key of main wallet (for TRX fee transfer)
 * @param {string} wallet.address_private_key - Private key of source wallet (for token transfer)
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback for the token transfer
 * @returns {void}
 */
const walletTransferTrcToMainWallet = (wallet, callback, confirmation) => {
    return sendFeeAndWithdrawToMain(wallet, callback, confirmation);
}

exports.walletTransferTrcToMainWallet = walletTransferTrcToMainWallet;


/**
 * Gets the balance of an address for TRX or TRC-20 tokens.
 * If contract address is provided, queries token balance; otherwise queries TRX balance.
 * Note: TRX uses 6 decimals, not 18 like Ethereum.
 * 
 * @param {string} address - Wallet address to check balance for (T...)
 * @param {string} contract - TRC-20 contract address (empty string for TRX balance)
 * @param {Function} callback - Receives (balance, decimals)
 * @returns {void}
 */
const getAccountBalance = async (address, contract, callback) => {
    if (contract != '') {
        // Query TRC-20 token balance
        createTokenContract(contract, async (instance) => {

            web3.setAddress(address);

            let res = await instance.balanceOf(address).call();

            // Also fetch decimals for proper formatting
            instance.decimals().call((error, decimals) => {
                callback(res.toString(), decimals);
            });

        });
    } else {
        // Query native TRX balance (6 decimals)
        web3.trx.getBalance(address).then((balance) => {
            callback(balance, 6);
        });
    }
}
exports.getAccountBalance = getAccountBalance;

/**
 * Creates a TronWeb contract instance for a TRC-20 token.
 * Uses TronWeb's contract().at() method for async contract loading.
 * 
 * @param {string} address - TRC-20 token contract address
 * @param {Function} callback - Receives the contract instance
 * @returns {void}
 */
const createTokenContract = (address, callback) => {
    web3.contract().at(address).then(callback);
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

const weiToNumber = (amount, decimals) => {

    let bnAmount = web3.toBigNumber(amount)
    let decimal = web3.toBigNumber('10').pow(web3.toBigNumber(decimals));

    return toFixed(bnAmount / decimal);
}

exports.weiToNumber = weiToNumber;

/**
 * Withdraws funds from the main wallet to an external address.
 * Automatically determines whether to use TRX or TRC-20 transfer based on contract presence.
 * 
 * @param {Object} wallet - Withdrawal configuration object
 * @param {string} wallet.address - Source (main) wallet address
 * @param {string} wallet.private_key - Private key for signing
 * @param {string} wallet.to - Destination wallet address
 * @param {number} wallet.amount - Amount to withdraw
 * @param {string} [wallet.contract] - Optional TRC-20 contract address; if present, uses token transfer
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Confirmation callback function
 * @returns {void}
 */
const walletWithdrawFromMainWallet = (wallet, callback, confirmation) => {
    if (wallet.contract) {
        return signMainWalletTrcTransaction(wallet, callback, confirmation);
    } else {
        return signMainWalletTrxTransaction(wallet, callback, confirmation);
    }
}

exports.walletWithdrawFromMainWallet = walletWithdrawFromMainWallet;

/**
 * Signs and broadcasts a TRX transfer transaction.
 * Checks account bandwidth before sending; if bandwidth is low, uses the deducted amount
 * to account for the TRX burned for bandwidth.
 * 
 * @param {Object} wallet - Transaction configuration object
 * @param {string} wallet.address - Sender wallet address (T...)
 * @param {string} wallet.private_key - Private key for signing
 * @param {string} wallet.to - Recipient wallet address
 * @param {number} wallet.amount - Amount of TRX to send
 * @param {number} [wallet.amount_deducted] - Amount minus bandwidth fee (used if bandwidth < 350)
 * @param {Function} callback - Error callback
 * @param {Function} confirmation - Success callback, receives transaction data
 * @returns {void}
 */
const signMainWalletTrxTransaction = (wallet, callback, confirmation) => {

        try {

            web3.trx.getBandwidth(wallet.address).then((bandwidth) => {

                let amount = wallet.amount;

                if(parseFloat(bandwidth) < 350 && wallet.amount_deducted) {
                    amount = wallet.amount_deducted;
                }

                web3.trx.sendTransaction(wallet.to, numberToWei(amount, 6), wallet.private_key).then((data) => {
                    confirmation(data);
                }).catch((error) => {
                    console.log(error);
                    callback(error);
                });

            });

        } catch (error) {
            console.log(error);
            throw error;
        }
};

exports.signMainWalletTrxTransaction = signMainWalletTrxTransaction;

/**
 * Signs and broadcasts a TRC-20 token transfer transaction.
 * Uses TronWeb's transactionBuilder to construct a smart contract call.
 * 
 * @param {Object} wallet - Token transfer configuration object
 * @param {string} wallet.address - Sender wallet address (must have tokens and TRX for energy)
 * @param {string} wallet.private_key - Private key for signing
 * @param {string} wallet.to - Recipient wallet address
 * @param {number} wallet.amount - Amount of tokens to send (in token units)
 * @param {string} wallet.contract - TRC-20 token contract address
 * @param {Function} callback - Receives the broadcast transaction result
 * @returns {void}
 */
const signMainWalletTrcTransaction = async (wallet, callback) => {

    try {

        createTokenContract(wallet.contract, async (instance) => {

            web3.setAddress(wallet.address);

            instance.decimals().call(async (error, decimals) => {

                let amount = wallet.amount;
                let parameter = [{type: 'address', value: wallet.to}, {type: 'uint256', value: numberToWei(amount, decimals).toString()}];
                let options = {
                    feeLimit: 100000000
                }

                const transactionObject = await web3.transactionBuilder.triggerSmartContract(
                    web3.address.toHex(wallet.contract),
                    "transfer(address,uint256)",
                    options,
                    parameter,
                    web3.address.toHex(wallet.address),
                );

                let signedTransaction = await web3.trx.sign(transactionObject.transaction, wallet.private_key);

                let broadcastTransaction = await web3.trx.sendRawTransaction(signedTransaction);

                callback(broadcastTransaction);
            });

        });

    } catch (error) {
        console.log(error);
        throw error;
    }
};

exports.signMainWalletTrcTransaction = signMainWalletTrcTransaction;

/**
 * Sends TRX (for energy/bandwidth) to a deposit address and then transfers TRC-20 tokens to main wallet.
 * This is necessary because deposit addresses typically don't have TRX for transaction fees.
 * 
 * TRON uses "energy" for smart contract calls (like TRC-20 transfers).
 * The function estimates energy required, calculates TRX needed, sends it, then transfers tokens.
 * 
 * @param {Object} deposit - Deposit configuration object
 * @param {string} deposit.address - Deposit address containing the tokens
 * @param {string} deposit.wallet - Main system wallet address
 * @param {string} deposit.contract - TRC-20 token contract address
 * @param {string} deposit.wei - Token amount in smallest unit
 * @param {string} deposit.private_key - Private key of main wallet (for TRX transfer)
 * @param {string} deposit.address_private_key - Private key of deposit address (for token transfer)
 * @param {number} deposit.amount - Token amount in human-readable format
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Success callback for the final token transfer
 * @returns {void}
 */
const sendFeeAndWithdrawToMain = (deposit, callback, confirmation) => {

    createTokenContract(deposit.contract, async (instance) => {

        // Estimate energy required for the token transfer
        estimateEnergy(deposit.contract, deposit.address, deposit.wallet, deposit.wei).then((response) => {

            console.log(response.data);

            let energy = response.data.energy_used;
            let amountInTrx = (1000 * parseFloat(energy) * 0.000001).toFixed(3);

            //if(deposit.nonce <= 1) {
            //    amountInTrx = 1;
            //}

            console.log('Fee amount in TRX: ' + amountInTrx);

            web3.trx.getBandwidth(deposit.address).then((bandwidth) => {

                //web3.trx.getBalance(address).then((balance) => {

                    //if(parseFloat(bandwidth) >= 400) {
                    //    sendTrcToMainWallet(instance, deposit, callback, confirmation)
                    //} else {

                        web3.trx.sendTransaction(deposit.address, numberToWei(amountInTrx, 6), deposit.private_key).then((data) => {
                            if (data.txid) {
                                console.log('Tx Hash For TRC movement is ' + data.txid);
                                sendTrcToMainWallet(instance, deposit, callback, confirmation)
                            }
                        }).catch((error) => {
                            console.log(error);
                            callback(error);
                        });
                    //}

                //});
            });
        });
    });
};

exports.sendFeeAndWithdrawToMain = sendFeeAndWithdrawToMain;

/**
 * Transfers TRC-20 tokens from a deposit address to the main wallet.
 * Called after TRX has been sent to the deposit address for energy/bandwidth.
 * 
 * @param {Object} instance - TronWeb contract instance for the TRC-20 token
 * @param {Object} deposit - Deposit configuration object
 * @param {string} deposit.address - Deposit address sending the tokens
 * @param {string} deposit.wallet - Main wallet receiving the tokens
 * @param {string} deposit.contract - TRC-20 token contract address
 * @param {number} deposit.amount - Token amount in human-readable format
 * @param {string} deposit.address_private_key - Private key for signing
 * @param {Function} callback - Error callback function
 * @param {Function} confirmation - Success callback, receives broadcast result
 * @returns {void}
 */
const sendTrcToMainWallet = (instance, deposit, callback, confirmation) => {

    web3.setAddress(deposit.address);

    instance.decimals().call(async (error, decimals) => {

        let parameter = [{type: 'address', value: deposit.wallet}, {
            type: 'uint256',
            value: numberToWei(deposit.amount, decimals).toString()
        }];

        let options = {
            feeLimit: 100000000
        }

        const transactionObject = await web3.transactionBuilder.triggerSmartContract(
            web3.address.toHex(deposit.contract),
            "transfer(address,uint256)",
            options,
            parameter,
            web3.address.toHex(deposit.address),
        );

        let signedTransaction = await web3.trx.sign(transactionObject.transaction, deposit.address_private_key);

        let broadcastTransaction = await web3.trx.sendRawTransaction(signedTransaction);

        confirmation(broadcastTransaction);

    });
};

exports.sendTrcToMainWallet = sendTrcToMainWallet;

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
 * Encodes parameters for TRON smart contract calls using ABI encoding.
 * Handles TRON's address format (41 prefix) and converts to Ethereum format for encoding.
 * 
 * @async
 * @param {Array} inputs - Array of {type, value} objects to encode
 * @returns {string} ABI-encoded parameters without 0x prefix
 */
async function encodeParams(inputs) {

    const AbiCoder = ethers.utils.AbiCoder;
    // TRON addresses start with 41 in hex, need to convert to 0x for encoding
    const ADDRESS_PREFIX_REGEX = /^(41)/;

    let typesValues = inputs
    let parameters = ''
    if (typesValues.length == 0)
        return parameters
    const abiCoder = new AbiCoder();
    let types = [];
    const values = [];
    for (let i = 0; i < typesValues.length; i++) {
        let { type, value } = typesValues[i];
        // Convert TRON address format to Ethereum format for ABI encoding
        if (type == 'address')
            value = value.replace(ADDRESS_PREFIX_REGEX, '0x');
        else if (type == 'address[]')
            value = value.map(v => toHex(v).replace(ADDRESS_PREFIX_REGEX, '0x'));
        types.push(type);
        values.push(value);
    }
    try {
        parameters = abiCoder.encode(types, values).replace(/^(0x)/, '');
    } catch (ex) {
        console.log(ex);
    }
    return parameters
}

/**
 * Estimates the energy required for a TRC-20 transfer.
 * Uses TronGrid's triggerconstantcontract API to simulate the transaction.
 * Energy is TRON's equivalent of Ethereum's gas for smart contract execution.
 * 
 * @async
 * @param {string} contractAddress - TRC-20 token contract address
 * @param {string} senderAddress - Address initiating the transfer
 * @param {string} receiverAddress - Address receiving the tokens
 * @param {string} amount - Token amount in smallest unit
 * @returns {Promise} Axios response with energy_used in response.data
 */
async function estimateEnergy(contractAddress, senderAddress, receiverAddress, amount) {
    let senderAddress_hex = web3.address.toHex(senderAddress);
    let inputs = [
        { type: 'address', value: senderAddress_hex },
        { type: 'uint256', value: amount }
    ]
    let data = await encodeParams(inputs);

    // Call TronGrid API to estimate energy consumption
    return {data: await web3.fullNode.request('wallet/triggerconstantcontract', {
        "owner_address": senderAddress,
        "contract_address": contractAddress,
        "function_selector": "transfer(address,uint256)",
        "parameter": data,
        "visible": true,
    }, 'post')};
}
