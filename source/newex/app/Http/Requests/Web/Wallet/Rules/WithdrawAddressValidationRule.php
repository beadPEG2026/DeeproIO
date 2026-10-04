<?php

namespace App\Http\Requests\Web\Wallet\Rules;

use App\Models\Wallet\WalletAddress;
use App\Services\PaymentGateways\Coin\Ripple\Services\RippleService;
use App\Services\PaymentGateways\Coin\Solana\Api\SolanaGateway;
use App\Services\PaymentGateways\Coin\Ton\Services\TonService;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Log;

class WithdrawAddressValidationRule implements Rule
{
    public $errorMessage = 'Invalid wallet address';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $address)
    {
        $network = request()->get('network');
        if (in_array((int)$network, [NETWORK_XLAYER, NETWORK_XLAYER20], true)) $address = \App\Services\Wallet\XLayerAddress::normalize($address);

        if(in_array($network, get_evm_networks()) && (!preg_match('/^0x(?i:[0-9a-f]){40}$/D', $address) || preg_match('/^0x0{40}$/i', $address))) {
            return false;
        }

        if (in_array($network, [NETWORK_TRX, NETWORK_TRC])) {
            try {
                $decoded = (new \StephenHill\Base58())->decode($address);
                if (strlen($decoded)!==25 || ord($decoded[0])!==0x41) return false;
                $payload=substr($decoded,0,21);
                if (!hash_equals(substr(hash('sha256',hash('sha256',$payload,true),true),0,4),substr($decoded,21))) return false;
            } catch (\Throwable $e) { return false; }
        }

        $ownAddresses = WalletAddress::where('user_id', auth()->id());
        if (in_array($network, get_evm_networks())) $ownAddresses->whereRaw('lower(address) = ?', [strtolower($address)]);
        else $ownAddresses->where('address', $address);
        if ($ownAddresses->exists()) {
            $this->errorMessage = 'The provided wallet address is your deposit address.';
            return false;
        }

        if(in_array($network, [NETWORK_BRC20, NETWORK_BTC])) {

            try {
                $response = bitcoind()->validateaddress($address)->get();

                if(!$response['isvalid']) {
                    return false;
                }

            } catch (\Exception $e) {
                Log::info('Can not connect to Bitcoin Node');
                $this->errorMessage = 'Address validation service is not available.';
                return false;
            }


        }

        if(in_array($network, [NETWORK_SOL, NETWORK_SOL_SPL])) {

            $response = (new SolanaGateway())->isValidSolanaAddress($address);

            if(!$response) {
                return false;
            }
        }

        if(in_array($network, [NETWORK_RIPPLE])) {

            $response = (new RippleService())->isValidXrpAddress($address);

            if(!$response) {
                return false;
            }
        }

        if(in_array($network, [NETWORK_TON])) {

            $response = (new TonService())->isValidTonAddress($address);

            if(!$response) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __($this->errorMessage);
    }
}

