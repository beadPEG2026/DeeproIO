<?php

namespace App\Console\Commands\Staking;

use App\Models\Staking\StakingUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};
class StakingRewardCalucationCommand extends Command
{
    protected $signature = 'staking:rewards-calculate {--apply : Accrue today only, after configured cutover}';
    protected $description = 'Preview staking accrual; apply once per stake/date without replaying historical days';
    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $cutover = config('deposits.staking_rewards_active_from');
        if ($apply && config('app.readonly')) {
            $this->error('STAKING_READ_ONLY');
            return self::FAILURE;
        }
        if ($apply && (!$cutover || now()->toDateString() < $cutover)) {
            $this->error('STAKING_CUTOVER_NOT_CONFIGURED');
            return self::FAILURE;
        }
        $processed = 0;
        $errors = 0;
        $date = now()->toDateString();
        StakingUser::active()->when(\Illuminate\Support\Facades\Schema::hasColumn('staking_users','meta'),fn($q)=>$q->whereNull('meta->funded_term'))->orderBy('id')->chunkById(100, function ($stakes) use ($apply, $date, &$processed, &$errors) {
            foreach ($stakes as $stake) {
                try {
                    DB::transaction(function () use ($stake, $apply, $date, &$processed) {
                        $s = StakingUser::whereKey($stake->id)->lockForUpdate()->first();
                        if (!$s || $s->status !== 'active') {
                            return;
                        }
                        if ((int) $s->days <= 0 || bccomp((string) $s->apy, '0', 18) < 0) {
                            throw new \RuntimeException('STAKING_INVALID_RULE');
                        }
                        $start = $s->value_date ?? $s->created_at;
                        $end = $s->redemption_date ?? $start->copy()->addDays($s->days);
                        if (now()->startOfDay()->lte($start) || now()->startOfDay()->gt($end)) {
                            return;
                        }
                        if (DB::table('staking_reward_receipts')->where('stake_id', $s->id)->where('reward_date', $date)->exists()) {
                            return;
                        }
                        // Preserve the legacy period-yield formula. Do not reinterpret APY or backfill missed dates.
                        $total = bcdiv(bcmul((string) $s->amount, (string) $s->apy, 24), '100', 18);
                        $remaining = bcsub($total, (string) $s->reward, 18);
                        if (bccomp($remaining, '0', 18) <= 0) {
                            return;
                        }
                        $daily = bcdiv($total, (string) $s->days, 18);
                        if (bccomp($daily, $remaining, 18) > 0) {
                            $daily = $remaining;
                        }
                        $before = (string) $s->reward;
                        $after = bcadd($before, $daily, 18);
                        if ($apply) {
                            $s->reward = $after;
                            $s->save();
                            DB::table('staking_reward_receipts')->insert(['stake_id' => $s->id, 'reward_date' => $date, 'amount' => $daily, 'balance_before' => $before, 'balance_after' => $after, 'rule' => 'legacy_period_yield_divided_by_days', 'created_at' => now()]);
                        }
                        $this->line(json_encode(['stake_id' => $s->id, 'date' => $date, 'accrual' => $daily, 'before' => $before, 'after' => $after, 'applied' => $apply]));
                        $processed++;
                    });
                } catch (\Throwable $e) {
                    $errors++;
                    Log::error('Staking accrual failed', ['stake_id' => $stake->id, 'code' => 'STAKING_ACCRUAL_FAILED']);
                }
            }
        });
        $this->info("Processed {$processed}; errors {$errors}; mode " . ($apply ? 'apply' : 'preview'));
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
