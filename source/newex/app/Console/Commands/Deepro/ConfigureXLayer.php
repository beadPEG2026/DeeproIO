<?php
namespace App\Console\Commands\Deepro;

use App\Models\Currency\Currency;
use App\Services\Custody\{AssetAutomation, CustodyBridge};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Setting;
use App\Models\Deposit\DepositChannel;
use App\Services\Deposit\{DepositChannelConfiguration, EvmDepositClient};

/** Explicit onboarding only. Never signs, sends funds or rewrites existing channel acceptance. */
final class ConfigureXLayer extends Command
{
    protected $signature = 'deepro:configure-xlayer {--apply : Create the reviewed asset bindings and draft channels} {--reuse-ethereum-wallet : Copy the existing Ethereum wallet into encrypted X Layer settings} {--enable-deposits : Verify and enable new X Layer deposit channels only} {--enable-withdrawals : Enable reviewed OKB/USDT withdrawals and custody; does not fund or send transactions}';
    protected $description = 'Prepare X Layer OKB/USDT with separate explicit deposit and withdrawal activation';
    public const USDT = '0x1e4a5963abfd975d8c9021ce480b42188849d41d';

    public function handle(): int
    {
        if (!$this->option('apply')) { $this->line('X Layer 196: OKB native (18), USDT '.self::USDT.' (6); networks 24/25. Dry run; no writes.');return self::SUCCESS; }
        if (!DB::table('networks')->where('id',24)->where('slug','xlayer')->exists() || !DB::table('networks')->where('id',25)->where('slug','xlayer20')->exists()) throw new \RuntimeException('Apply the X Layer schema migration first.');
        $usdt=Currency::where('symbol','USDT')->firstOrFail();
        if ($usdt->xlayer_contract && strtolower($usdt->xlayer_contract)!==self::USDT) throw new \RuntimeException('Existing X Layer USDT contract conflicts with the reviewed registry.');
        $wallet=null;
        if ($this->option('reuse-ethereum-wallet')) {
            $address=trim((string)setting('ethereum.wallet'));$key=(string)setting('ethereum.private_key');
            if (!$address || !$key) throw new \RuntimeException('Ethereum wallet is not configured.');
            if (setting('xlayer.wallet') && strtolower((string)setting('xlayer.wallet'))!==strtolower($address)) throw new \RuntimeException('X Layer already has a different wallet; use the migration workflow.');
            app(CustodyBridge::class)->call('xlayer','validate',['sender'=>$address,'private_key'=>$key]);
            $wallet=[$address,$key];
        }
        DB::transaction(function()use($usdt,$wallet){
            $okb=Currency::firstOrCreate(['symbol'=>'OKB'],['name'=>'OKB','alt_symbol'=>'OKB','type'=>'coin','decimals'=>8,'status'=>true,'deposit_status'=>false,'withdraw_status'=>false,'min_deposit'=>'0.001','min_withdraw'=>'0.01','min_deposit_confirmation'=>64,'txn_explorer'=>'https://www.okx.com/web3/explorer/xlayer/tx/%txid%']);
            $usdt->update(['xlayer_contract'=>self::USDT]);
            foreach ([[$okb,24],[$usdt,25]] as [$currency,$network]) {
                if (!DB::table('currency_networks')->where('currency_id',$currency->id)->where('network_id',$network)->exists()) DB::table('currency_networks')->insert(['currency_id'=>$currency->id,'network_id'=>$network]);
                app(AssetAutomation::class)->draft($currency->refresh());
            }
            if ($wallet) {Setting::set('xlayer.wallet',$wallet[0]);Setting::set('xlayer.private_key',$wallet[1]);Setting::save();}
            DB::table('custody_audits')->insert(['action'=>'xlayer.configured','actor_id'=>null,'detail'=>json_encode(['wallet_reused_from_ethereum'=>(bool)$wallet,'public_activation'=>false,'contract'=>self::USDT]),'created_at'=>now()]);
        });
        if ($this->option('enable-deposits')) {
            $client=app(EvmDepositClient::class);
            $start=max(0,$client->height('xlayer')-63);
            DB::transaction(function()use($start){
                foreach (DepositChannel::where('chain','xlayer')->get() as $channel) {
                    if ($channel->state === 'active') continue;
                    if (!in_array($channel->state,['draft','validated'],true)) throw new \RuntimeException('Existing channel is stopped or in a pilot; review it in the admin console.');
                    $data=$channel->only(['currency_id','network_id','contract','decimals','confirmations','minimum','fee_fixed','fee_percent']);
                    app(DepositChannelConfiguration::class)->save($data+['start_block'=>$channel->start_block??$start,'state'=>'active'],null,'xlayer-authorized-onboarding');
                    Currency::where('id',$channel->currency_id)->update(['deposit_status'=>true]);
                    DB::table('networks')->where('id',$channel->network_id)->update(['deposit_status'=>true,'updated_at'=>now()]);
                }
            });
            $this->info('Deposit configuration enabled; scanner health must pass before an address is offered. Withdrawals remain unchanged.');
        }
        if($this->option('enable-withdrawals'))$this->enableWithdrawals();
        $this->info('OKB / USDT configured. Verify channel status, scanner health and hot-wallet inventory in the admin console.');
        return self::SUCCESS;
    }

    private function enableWithdrawals(): void
    {
        abort_if(config('app.readonly'),403);
        $sender=(string)setting('xlayer.wallet');$key=(string)setting('xlayer.private_key');
        if(!$sender || !$key)throw new \RuntimeException('X Layer signing configuration is missing');
        app(CustodyBridge::class)->call('xlayer','validate',['sender'=>$sender,'private_key'=>$key]);
        foreach(DepositChannel::where('chain','xlayer')->get() as $channel)app(EvmDepositClient::class)->validateToken($channel);
        DB::transaction(function(){
            $policy=DB::table('custody_networks')->where('chain','xlayer')->lockForUpdate()->first();
            if(!$policy || bccomp((string)$policy->max_fee,'0',18)<=0)throw new \RuntimeException('A positive X Layer transaction fee cap is required');
            foreach([['OKB',24],['USDT',25]] as [$symbol,$network]){
                $c=Currency::where('symbol',$symbol)->lockForUpdate()->firstOrFail();
                if(!DB::table('currency_networks')->where('currency_id',$c->id)->where('network_id',$network)->exists())throw new \RuntimeException('Missing X Layer asset binding');
                $c->update(['withdraw_status'=>true,'disabled_withdrawal_networks'=>array_values(array_diff($c->disabled_withdrawal_networks,[$network]))]);
                DB::table('networks')->where('id',$network)->update(['withdraw_status'=>true,'updated_at'=>now()]);
            }
            DB::table('custody_networks')->where('chain','xlayer')->update(['enabled'=>true,'updated_at'=>now()]);
            DB::table('custody_audits')->insert(['action'=>'xlayer.withdrawals_enabled','detail'=>json_encode(['native'=>'OKB','token'=>'USDT','gas_funding_changed'=>false,'auto_sweep_changed'=>false,'funded_transfer_verified'=>false]),'created_at'=>now()]);
        });
        $this->info('X Layer withdrawals enabled. Existing balance, fee, approval and receipt checks remain enforced; no transaction was sent.');
    }
}
