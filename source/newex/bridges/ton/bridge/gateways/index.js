const bodyParser = require('body-parser');
const crypto = require('crypto');
const { mnemonicNew, mnemonicToPrivateKey } = require('@ton/crypto');
const { TonClient, WalletContractV4, internal } = require('@ton/ton');
const { Address, fromNano, Cell, Slice} = require('@ton/core');
const { axios } = require('axios');
const gateways = (app) => {
  app.use(bodyParser.json());
  app.use(bodyParser.urlencoded({ extended: true }));

  const client = new TonClient(global.ton);

  // Health/ping
  app.get('/', (req, res) => {
    res.end(JSON.stringify({ ok: true, name: 'TON Bridge' }));
  });

  // Create TON system wallet using TonWeb
  app.get('/wallet/create', async function (req, res) {
    try {
        const mnemonics = await mnemonicNew(24);

        const keyPair = await mnemonicToPrivateKey(mnemonics);

        const wallet = WalletContractV4.create({
            workchain: 0,
            publicKey: keyPair.publicKey,
        });

        // Step 5: Open wallet and get address
        const contract = client.open(wallet);
        const address = wallet.address.toString();

      return res.end(JSON.stringify({
        address: address,
        private_key: mnemonics.join(' '),
      }));

    } catch (e) {
      console.log(e);
      return res.status(500).end(JSON.stringify({ error: 'wallet_create_failed' }));
    }
  });

  app.post('/wallet/withdraw/ton', async function (req, res) {
    try {
      const id = req.body.id;
      const to = req.body.to;
      const amount = req.body.amount;
      const address = req.body.address;
      const memo = req.body.memo;
      const private_key = req.body.private_key;

      console.log('TON withdraw request:', { id, to, amount });

      const keyPair = await mnemonicToPrivateKey(private_key.split(' '));

      const wallet = WalletContractV4.create({
          workchain: 0,
          publicKey: keyPair.publicKey,
      });

      const openedWallet = client.open(wallet);

      // Prepare destination and amount
      const { Address, toNano } = require('@ton/ton');
      const toAddress = Address.parse(to);

      const seqno = await openedWallet.getSeqno();

      await openedWallet.sendTransfer({
          seqno,
          secretKey: keyPair.secretKey,
          messages: [
              internal({
                  to: toAddress,
                  value: String(amount),
                  bounce: false,
                  body: memo
              }),
          ],
          sendMode: 3,
      });

      const state = await client.getContractState(wallet.address);
      console.log("Deployed:", state);
      const { hash } = state.lastTransaction;

      let txIdentifier = hash.toString('hex');
      console.log('TON Withdraw transaction sent, tx hash:', txIdentifier);

      // Return success with tx hash - PHP handles DB updates
      return res.json({ success: true, txHash: txIdentifier, message: 'TON withdrawal completed' });
    } catch (error) {
      console.log('TON withdraw error:', error);
      // Return error - PHP handles DB updates
      return res.json({ success: false, error: error.message || 'Withdrawal failed' });
    }
  });

  app.post('/wallet/validate/ton', async function (req, res) {
    try {
      const { address } = req.body || {};
      if (!address || typeof address !== 'string') {
        return res.status(400).end(JSON.stringify({ success: false, message: 'Address is required' }));
      }
      const { Address } = require('@ton/ton');
      try {
        Address.parse(address);
        return res.end(JSON.stringify({ success: true }));
      } catch (e) {
        return res.end(JSON.stringify({ success: false }));
      }
    } catch (error) {
      console.log(error);
      return res.end(JSON.stringify({ success: false }));
    }
  });

  app.post('/wallet/balance/ton', async function (req, res) {
    try {
      const address = req.body.address;
      console.log('Get TON balance of', address);
      const { Address, fromNano } = require('@ton/ton');
      const addr = Address.parse(address);
      const balanceNano = await client.getBalance(addr);
      const balanceTon = fromNano ? fromNano(balanceNano) : (Number(balanceNano) / 1e9).toString();
      return res.end(JSON.stringify({ success: true, message: parseFloat(balanceTon) }));
    } catch (error) {
      console.log(error);
      return res.end(JSON.stringify({ success: false, message: 'Could not fetch balance' }));
    }
  });

  app.post('/wallet/transactions/ton', async function (req, res) {
    try {
      const address = (req.body && req.body.address) ? req.body.address : null;
      const limit = (req.body && req.body.limit) ? parseInt(req.body.limit) : 50;
      if (!address) {
        return res.status(400).end(JSON.stringify({ success: false, message: 'Address is required', transactions: [] }));
      }

        let userAddress = Address.parse(address);

        const transactions = await client.getTransactions(
            userAddress, // wallet address
            50 // number of transactions
        );

        const incomingDeposits = transactions.filter(tx => {
            const inMsg = tx.inMessage;

            // Check if it's an internal message (from another wallet)
            const isInternal = inMsg?.info?.type === 'internal';

            // Check if the message was sent TO your wallet
            const isIncoming = inMsg?.info?.dest.toString() === address;

            const isRefund = inMsg?.info?.src && inMsg?.info?.src.toString() === address;

            const isBounced = inMsg?.info?.bounced === true;

            // Check if the transaction was successful
            const isSuccessful = tx.description?.aborted === false;

            return isInternal && isIncoming && isSuccessful && !isRefund && !isBounced;
        });

      const list = incomingDeposits ? incomingDeposits : [];

      const deposits = [];


      for (const tx of list) {

        const inMsg = tx.inMessage.info;
        const amountTon = fromNano(inMsg.value.coins);

        const value = tx.inMessage.info.value.coins;
        const hexedMemo = extractHex(tx.inMessage.body?.toString() || '') || null;
        const memo = hexedMemo ? Buffer.from(hexedMemo.slice(8), 'hex').toString('utf-8') : '';

        const hash = tx.hash().toString('hex');

          deposits.push({
            from: inMsg.src.toString(),
            destination: inMsg.dest.toString(),
            amount: amountTon,
            full_amount: value.toString(),
            tx_hash: hash,
            memo: memo,
          });
      }

      return res.end(JSON.stringify({ success: true, transactions: deposits }));
    } catch (error) {
      console.log('TON transactions error', error);
      return res.end(JSON.stringify({ success: false, transactions: [] }));
    }
  });
  /*************************************************************************************************************
   * MERCHANT MODULE ENDPOINTS
   * These endpoints do NOT update platform tables - they return results for PHP to handle
   *************************************************************************************************************/

  // Merchant: Transfer TON from hot wallet to destination (payouts/refunds)
  app.post('/wallet/transfer/ton', async function (req, res) {
    console.log("Merchant: Transfer TON to destination");
    console.log(req.body);

    try {
      const to = req.body.address;
      const amount = req.body.amount;
      const memo = req.body.memo || '';
      const private_key = req.body.private_key;

      const keyPair = await mnemonicToPrivateKey(private_key.split(' '));

      const wallet = WalletContractV4.create({
          workchain: 0,
          publicKey: keyPair.publicKey,
      });

      const openedWallet = client.open(wallet);

      const { Address } = require('@ton/ton');
      const toAddress = Address.parse(to);

      const seqno = await openedWallet.getSeqno();

      await openedWallet.sendTransfer({
          seqno,
          secretKey: keyPair.secretKey,
          messages: [
              internal({
                  to: toAddress,
                  value: String(amount),
                  bounce: false,
                  body: memo
              }),
          ],
          sendMode: 3,
      });

      const state = await client.getContractState(wallet.address);
      const { hash } = state.lastTransaction;
      let txIdentifier = hash.toString('hex');

      console.log('Merchant TON Transfer Confirmed: ' + txIdentifier);
      return res.json({ success: true, txHash: txIdentifier });

    } catch (error) {
      console.log('Merchant TON transfer error:', error);
      return res.json({ success: false, error: error.message });
    }
  });
};

exports.gateways = gateways;

function extractHex(input) {
    const match = input.match(/^x\{([0-9a-fA-F]+)\}$/);
    return match ? match[1] : null;
}
