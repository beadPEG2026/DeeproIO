<?php
namespace App\Services\PaymentGateways\Coin\Bitcoin\Api;

use App\Helpers\PaymentGateways\Bnb\BnbNodeHelper;
use App\Interfaces\PaymentsGateways\Coin\CoinGatewayInterface;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Setting;

class BitcoinGateway implements CoinGatewayInterface
{
    /**
     * Used to generate address wallet per currency
     * @return string
     */
    public static function client() {
        $name=\Illuminate\Support\Facades\DB::table('bitcoin_wallet_control')->where('id',1)->value('legacy_wallet_name');
        return $name!==null?bitcoind()->wallet($name):bitcoind();
    }
    public function createBitcoinAddress() {
        return self::client()->getnewaddress()->get();
    }

    /** Allocate a BTC deposit address whose signing keys stay in Bitcoin Core. */
    public function createOwnedBitcoinAddress(): string
    {
        $manager=app(\App\Services\Wallet\BitcoinWalletManager::class);
        if($active=$manager->active())return $manager->locked(function()use($active){
            $rpc=app(\App\Services\Wallet\BitcoinWalletRpc::class);$rpc->ready($active->name,true);
            $address=$rpc->call('getnewaddress',['deepro-deposit','bech32'],$active->name);
            if(($rpc->call('getaddressinfo',[$address],$active->name)['ismine']??false)!==true)throw new \RuntimeException('BTC_ADDRESS_NOT_OWNED');
            return $address;
        });
        $wallet = self::client()->getwalletinfo()->result();
        if (!is_array($wallet) || ($wallet['private_keys_enabled'] ?? false) !== true) {
            throw new \RuntimeException('BTC_WALLET_CANNOT_SIGN');
        }
        $address = self::client()->getnewaddress()->result();
        if (!is_string($address) || trim($address) === '') {
            throw new \RuntimeException('BTC_ADDRESS_NOT_GENERATED');
        }
        $info = self::client()->getaddressinfo($address)->result();
        if (!is_array($info) || ($info['ismine'] ?? false) !== true
            || ($info['iswatchonly'] ?? false) === true || ($info['address'] ?? null) !== $address) {
            throw new \RuntimeException('BTC_ADDRESS_NOT_OWNED');
        }
        return $address;
    }

    /**
     * Used to generate data to withdraw
     * @param $address
     * @param $amount
     * @return array
     */
    public function withdraw($type, $withdrawal, $address, $amount)
    {
        return app(\App\Services\Wallet\BitcoinWalletManager::class)->locked(function()use($withdrawal,$address,$amount){
        if(app(\App\Services\Wallet\BitcoinWalletManager::class)->active())return app(\App\Services\Custody\CustodyService::class)->withdrawal($withdrawal);
        $response = self::client()->sendToAddress($address, $amount);

        $message = '';
        $source = '';

        if ($response->hasError()) {
            $status = STATUS_VALIDATION_ERROR;
        } else {
            $status = STATUS_OK;
            $message = generate_string();
            $source = $message;
        }

        //return the tx id
        $txn = $response->get();

        if($txn) {
            $withdrawal->txn = $txn;
            $withdrawal->update();
        }

        return [
            'status' => $status,
            'source' => $source,
            'message' => $message,
        ];
        });
    }

    public function withdrawBrc($withdrawal, $txn)
    {
        $status = STATUS_OK;

        if($txn) {
            $withdrawal->txn = $txn;
            $withdrawal->update();
        } else {
            $status = STATUS_VALIDATION_ERROR;
        }

        return [
            'status' => $status,
            'source' => '',
            'message' => generate_string(),
        ];
    }

    public static function send($address, $amount)
    {
        try {
            $response = self::client()->sendToAddress($address, $amount);
        } catch (BitcoindException $bitcoindException) {
            throw new WithdrawException('Unable to send via bitcoind->sendtoaddress', $bitcoindException->getMessage());
        }

        if ($response->hasError()) {
            throw new WithdrawException('Unable to send via bitcoind->sendtoaddress', $response->error());
        }

        //return the tx id
        return $response->get();
    }
}
