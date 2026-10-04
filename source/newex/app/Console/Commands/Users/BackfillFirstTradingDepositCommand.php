<?php

namespace App\Console\Commands\Users;

use App\Models\User\User;
use App\Models\Deposit\Deposit;
use App\Models\Deposit\FiatDeposit;
use App\Repositories\Currency\CurrencyRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillFirstTradingDepositCommand extends Command
{
    protected $signature = 'users:backfill-first-deposit {--userId=} {--dry-run}';

    protected $description = 'Set users.first_trading_deposit_usd using earliest confirmed deposit (only once per user)';

    public function handle()
    {
        $dryRun = (bool)$this->option('dry-run');
        $singleUserId = $this->option('userId');

        $query = User::query()->whereNull('first_trading_deposit_usd');
        if ($singleUserId) {
            $query->where('id', $singleUserId);
        }

        $count = $query->count();
        if ($count === 0) {
            $this->info('No users found without first_trading_deposit_usd.');
            return Command::SUCCESS;
        }

        $this->info("Processing {$count} user(s) to backfill first_trading_deposit_usd...");
        $currencyRepo = new CurrencyRepository();

        $processed = 0;
        $query->orderBy('id')->chunkById(500, function ($users) use (&$processed, $currencyRepo, $dryRun) {
            foreach ($users as $user) {
                try {
                    // Get earliest confirmed crypto deposit
                    $crypto = Deposit::query()
                        ->where('user_id', $user->id)
                        ->confirmed()
                        ->orderBy('created_at', 'asc')
                        ->first();

                    // Get earliest confirmed fiat deposit
                    $fiat = FiatDeposit::query()
                        ->where('user_id', $user->id)
                        ->confirmed()
                        ->orderBy('created_at', 'asc')
                        ->first();

                    $chosen = null;
                    if ($crypto && $fiat) {
                        $chosen = $crypto->created_at <= $fiat->created_at ? $crypto : $fiat;
                    } elseif ($crypto) {
                        $chosen = $crypto;
                    } elseif ($fiat) {
                        $chosen = $fiat;
                    }

                    if (!$chosen) {
                        // No confirmed deposits yet for this user
                        continue;
                    }

                    // Load currency to compute USD value
                    $currency = $chosen->currency; // relation exists on both models
                    if (!$currency) {
                        continue; // cannot compute without currency reference
                    }

                    // Determine amount field
                    $amount = $chosen->amount ?? null;
                    if (is_null($amount)) {
                        continue;
                    }

                    $priceUsd = $currencyRepo->currencyPriceInUsd($currency);
                    $usdValue = math_multiply($amount, $priceUsd);

                    if ($dryRun) {
                        $this->line("[DRY] User #{$user->id}: first deposit {$amount} {$currency->symbol} => {$usdValue} USD");
                        continue;
                    }

                    // Set only if still null to avoid race with other processes
                    DB::transaction(function () use ($user, $usdValue) {
                        $u = User::query()->where('id', $user->id)->lockForUpdate()->first();
                        if (is_null($u->first_trading_deposit_usd)) {
                            $u->first_trading_deposit_usd = $usdValue;
                            $u->save();
                        }
                    });

                    $processed++;
                } catch (\Throwable $e) {
                    $this->error("Failed processing user #{$user->id}: " . $e->getMessage());
                }
            }
        });

        $this->info("Done. Processed {$processed} user(s).");
        return Command::SUCCESS;
    }
}
