const solanaWeb3 = require("@solana/web3.js");
const config = require("./config");

const connection = new solanaWeb3.Connection(config.rpcUrl, "confirmed");

module.exports = connection;