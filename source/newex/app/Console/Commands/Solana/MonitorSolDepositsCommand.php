<?php
namespace App\Console\Commands\Solana;

use App\Models\Deposit\DepositChannel;
use App\Models\Wallet\WalletAddress;
use App\Services\Deposit\{SolanaDepositClient, VerifiedChainDeposit, DepositChannelPolicy};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};
use RuntimeException;

final class MonitorSolDepositsCommand extends Command
{
    protected $signature = 'solana:monitor-sol-deposits';
    protected $description = 'Scan finalized SOL transfers with persistent signature pagination and verified credits';
    public function handle(): int
    {
        if (!DB::selectOne('SELECT pg_try_advisory_lock(77321,20) AS acquired')->acquired) return self::SUCCESS;
        try {
            $failed=false;
            foreach (DepositChannel::where('chain','solana')->whereIn('state',['active','pilot','tested'])->get() as $c) {
                try {
                    if (app(DepositChannelPolicy::class)->error($c->currency_id,$c->network_id,false,null,true)) throw new RuntimeException('SOL_CHANNEL_UNAVAILABLE');
                    app(SolanaDepositClient::class)->ready();
                    $query=WalletAddress::whereIn('network_id',[NETWORK_SOL,NETWORK_SOL_SPL])->whereNull('token_account')->has('user')->orderBy('id');
                    if ($c->isPilot()) $query->whereIn('user_id',$c->pilotUsers());
                    $complete=true;
                    foreach ($query->get()->unique('address') as $address) {
                        if (!$this->check($address,$c)) $complete=false;
                    }
                    if (!$complete) throw new RuntimeException('SOL_SCAN_BACKLOG');
                    DB::table('chain_deposit_scan_states')->updateOrInsert(['chain'=>'solana','scope'=>$c->scanScope()],['last_success_at'=>now(),'last_error'=>null,'updated_at'=>now()]);
                } catch (\Throwable $e) {
                    $failed=true;
                    $code=preg_match('/^(SOL|DEPOSIT)_[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'SOL_SCAN_EXCEPTION';
                    DB::table('chain_deposit_scan_states')->updateOrInsert(['chain'=>'solana','scope'=>$c->scanScope()],['last_error'=>$code,'updated_at'=>now()]);
                    Log::error('SOL scanner failed',['channel_id'=>$c->id,'code'=>$code]);
                    $this->error($code);
                }
            }
            return $failed?self::FAILURE:self::SUCCESS;
        } finally { DB::select('SELECT pg_advisory_unlock(77321,20)'); }
    }
    public function check(WalletAddress $address, DepositChannel $channel): bool
    {
        $identity=['chain'=>'solana','scope'=>$channel->scanScope().':address:'.$address->id];
        DB::table('chain_deposit_scan_states')->insertOrIgnore($identity+['updated_at'=>now()]);
        for ($page=0;$page<5;$page++) {
            $done=DB::transaction(function() use($address,$channel,$identity) {
                $state=DB::table('chain_deposit_scan_states')->where($identity)->lockForUpdate()->first();
                $cursor=$state->fingerprint?json_decode($state->fingerprint,true,512,JSON_THROW_ON_ERROR):[];
                $client=app(SolanaDepositClient::class);
                $rows=$client->signatures($address->address,$cursor['before']??null,$cursor['checkpoint']??null);
                $head=$cursor['head']??($rows[0]['signature']??($cursor['checkpoint']??null));
                $last=$cursor['before']??null;
                $done=count($rows)<100;
                foreach ($rows as $row) {
                    if ($row['signature']===$last) throw new RuntimeException('SOL_STALLED_CURSOR');
                    $last=$row['signature'];
                    // Index rows discover signatures; only final receipts supply amounts and destinations.
                    if ($row['err']!==null) continue;
                    foreach ($client->transfers($last,$address->address,$row['slot']) as $proof) {
                        if ($proof['timestamp'] < $address->created_at->getTimestamp()*1000) continue;
                        if ($channel->start_block!==null && $proof['block']<$channel->start_block) continue;
                        if ($proof['sender']===trim((string)setting('solana.wallet'))) continue;
                        app(VerifiedChainDeposit::class)->process($channel,$address,$proof);
                    }
                }
                $next=$done?['checkpoint'=>$head]:['checkpoint'=>$cursor['checkpoint']??null,'before'=>$last,'head'=>$head];
                DB::table('chain_deposit_scan_states')->where($identity)->update(['fingerprint'=>json_encode($next),'last_success_at'=>now(),'last_error'=>$done?null:'SOL_SCAN_BACKLOG','updated_at'=>now()]);
                return $done;
            },3);
            if ($done) return true;
        }
        return false;
    }
}
