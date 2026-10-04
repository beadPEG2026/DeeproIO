<?php
namespace App\Console\Commands\Deepro;

use App\Models\Deposit\DepositChannel;
use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Services\Deposit\{DepositChannelConfiguration, DepositChannelReadiness, DepositChannelPolicy};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Authorized server operations; uses exactly the same validation as the admin form. */
final class OpenDepositChannels extends Command
{
    protected $signature = 'deepro:open-deposit-channels {--channel=*} {--apply : Persist public activation and deposit switches}';
    protected $description = 'Open technically verified configured channels; funded acceptance remains separately recorded';

    public function handle(): int
    {
        if (config('app.readonly')) { $this->error('Application is read-only'); return self::FAILURE; }
        $q = DepositChannel::with(['currency', 'network'])->orderBy('id');
        if ($this->option('channel')) $q->whereIn('id', $this->option('channel'));
        $failed = false; $readiness = new DepositChannelReadiness();
        foreach ($q->get() as $channel) {
            try {
                if (!$channel->currency?->status || !$channel->network?->status) throw new \RuntimeException('DEPOSIT_ASSET_OR_NETWORK_DISABLED');
                $data = $channel->only(['currency_id','network_id','contract','decimals','confirmations','minimum','fee_fixed','fee_percent','start_block','acceptance_reference','pilot_user_ids','pilot_minimum','pilot_limit']);
                if ($channel->chain !== 'tron' && $channel->start_block === null) $data['start_block'] = $readiness->inspect($channel)['suggested_start_block'];
                $data['state'] = 'active';
                // Dry runs validate the actual save path inside an outer rollback transaction.
                DB::beginTransaction();
                try {
                    DB::select('SELECT pg_advisory_xact_lock(77321,99)');
                    $current = DepositChannel::lockForUpdate()->findOrFail($channel->id);
                    if ($current->digest() !== $channel->digest() || $current->state !== $channel->state) throw new \RuntimeException('DEPOSIT_CONFIGURATION_CHANGED');
                    $asset = Currency::lockForUpdate()->findOrFail($channel->currency_id);
                    $network = Network::lockForUpdate()->findOrFail($channel->network_id);
                    $switches = ['asset_deposit_status' => $asset->deposit_status, 'network_deposit_status' => $network->deposit_status, 'disabled_deposit_networks' => $asset->disabled_deposit_networks];
                    $asset->deposit_status = true;
                    $asset->disabled_deposit_networks = array_values(array_diff($asset->disabled_deposit_networks ?? [], [$network->id]));
                    $asset->save(); $network->deposit_status = true; $network->save();
                    $saved = app(DepositChannelConfiguration::class)->save($data, null, 'authorized-server-operations');
                    if ($error = app(DepositChannelPolicy::class)->error($saved->currency_id, $saved->network_id, false)) throw new \RuntimeException($error);
                    DB::table('deposit_channel_audits')->insert(['channel_id'=>$saved->id,'actor_id'=>null,'before'=>json_encode(['deposit_switches'=>$switches]),'after'=>json_encode(['source'=>'authorized-server-operations','deposit_switches'=>['asset_deposit_status'=>true,'network_deposit_status'=>true,'disabled_deposit_networks'=>$asset->disabled_deposit_networks],'withdrawals_changed'=>false]),'created_at'=>now()]);
                    if ($this->option('apply')) DB::commit(); else DB::rollBack();
                } catch (\Throwable $e) { DB::rollBack(); throw $e; }
                $this->line(json_encode(['channel_id'=>$channel->id,'symbol'=>$channel->currency->symbol,'chain'=>$channel->chain,'applied'=>(bool)$this->option('apply'),'configuration_verified'=>true,'funded_test_required'=>false,'state'=>$this->option('apply')?'active':$channel->state]));
            } catch (\Throwable $e) {
                $failed = true;
                $message = $e instanceof \Illuminate\Validation\ValidationException ? implode('; ', array_merge(...array_values($e->errors()))) : $e->getMessage();
                // Validation messages are generated locally; provider transport messages are never printed.
                $safe = $e instanceof \Illuminate\Validation\ValidationException || preg_match('/^DEPOSIT_[A-Z0-9_]+$/', $message);
                $this->line(json_encode(['channel_id'=>$channel->id,'symbol'=>$channel->currency?->symbol,'applied'=>false,'error'=>$safe?$message:'DEPOSIT_OPEN_FAILED']));
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
