<?php

namespace App\Console\Commands\Deepro;

use App\Models\Deposit\DepositChannel;
use App\Services\Deposit\DepositChannelReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PlanDepositChannels extends Command
{
    protected $signature = 'deepro:plan-deposit-channels {--channel=*} {--apply-drafts : Fill missing draft start blocks only; never enable scanning}';
    protected $description = 'Verify chain identity, token precision and history-based deposit scan starts';
    public function handle(): int
    {
        $q = DepositChannel::with(['currency', 'network'])->where('state', 'draft');
        if ($this->option('channel')) $q->whereIn('id', $this->option('channel'));
        $readiness = new DepositChannelReadiness(); $failed = false;
        foreach ($q->orderBy('id')->get() as $c) {
            try {
                $plan = $readiness->inspect($c);
                if (!$plan['precision_matches']) throw new \RuntimeException('DEPOSIT_DECIMALS_MISMATCH');
                $plan['applied'] = false;
                if ($this->option('apply-drafts') && $c->chain !== 'tron' && $c->start_block === null) {
                    $plan['applied'] = DB::transaction(function () use ($c, $plan) {
                        DB::select('SELECT pg_advisory_xact_lock(77321,99)');
                        $row = DepositChannel::lockForUpdate()->findOrFail($c->id);
                        if ($row->state !== 'draft' || $row->start_block !== null || $row->digest() !== $c->digest()) return false;
                        $before = $row->toArray();
                        $row->update(['start_block' => $plan['suggested_start_block'], 'config_digest' => null]);
                        DB::table('deposit_channel_audits')->insert(['channel_id' => $row->id, 'actor_id' => null, 'before' => json_encode($before), 'after' => json_encode(['configuration' => $row->fresh()->toArray(), 'source' => 'system-draft-initialization', 'readiness' => $plan]), 'created_at' => now()]);
                        return true;
                    });
                }
                $this->line(json_encode($plan, JSON_UNESCAPED_SLASHES));
            } catch (\Throwable $e) {
                $failed = true;
                $this->line(json_encode(['channel_id' => $c->id, 'applied' => false, 'error' => preg_match('/^DEPOSIT_[A-Z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'DEPOSIT_PREFLIGHT_FAILED']));
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
