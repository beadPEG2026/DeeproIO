<?php

namespace Database\Seeders\Demo;

use App\Models\Currency\Currency;
use App\Models\Launchpad\Launchpad;
use App\Models\Staking\Staking;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class StakingLaunchpadSeeder extends Seeder
{
    /**
     * Seed demo staking and launchpad data.
     *
     * @return void
     */
    public function run()
    {
        // Get existing currencies (need at least 4)
        $currencies = Currency::where('type', 'coin')
            ->whereNotNull('symbol')
            ->take(8)
            ->get();

        if ($currencies->count() < 4) {
            $this->command->warn('Not enough currencies found. Please seed currencies first.');
            return;
        }

        $this->seedStakings($currencies);
        $this->seedLaunchpads($currencies);

        $this->command->info('Demo staking and launchpad data seeded successfully!');
    }

    /**
     * Seed 4 staking entries with different statuses.
     */
    private function seedStakings($currencies)
    {
        $stakingData = [
            [
                'currency_id' => $currencies[0]->id,
                'allowed_days' => '30,60,90,180',
                'rewards_percentage' => '5,8,12,18',
                'min_amount' => '0.01',
                'max_amount' => '100',
                'status' => 'active',
            ],
            [
                'currency_id' => $currencies[1]->id,
                'allowed_days' => '14,30,60',
                'rewards_percentage' => '3,6,10',
                'min_amount' => '10',
                'max_amount' => '10000',
                'status' => 'active',
            ],
            [
                'currency_id' => $currencies[2]->id,
                'allowed_days' => '7,14,30,60,90',
                'rewards_percentage' => '2,4,7,11,15',
                'min_amount' => '0.1',
                'max_amount' => '500',
                'status' => 'active',
            ],
            [
                'currency_id' => $currencies[3]->id,
                'allowed_days' => '30,90,180,365',
                'rewards_percentage' => '8,15,25,40',
                'min_amount' => '1',
                'max_amount' => '1000',
                'status' => 'inactive',
            ],
        ];

        foreach ($stakingData as $data) {
            // Check if staking already exists for this currency
            if (!Staking::where('currency_id', $data['currency_id'])->exists()) {
                Staking::create($data);
            }
        }
    }

    /**
     * Seed 4 launchpad entries with different states.
     */
    private function seedLaunchpads($currencies)
    {
        $now = Carbon::now();

        $launchpadData = [
            // Active/Open launchpad - currently running
            [
                'name' => $currencies[4]->name ?? $currencies[0]->name . ' Token Sale',
                'description' => 'Join our exciting token launch! This is a demo launchpad showcasing an active token sale with great potential. Early investors can benefit from exclusive rates.',
                'currency_id' => $currencies[4]->id ?? $currencies[0]->id,
                'network_id' => $currencies[4]->network_id ?? 2,
                'rate' => '0.05',
                'min_buy' => '10',
                'max_buy' => '5000',
                'soft_cap' => '50000',
                'hard_cap' => '100000',
                'raised_amount' => '35000',
                'start_time' => $now->copy()->subDays(5),
                'end_time' => $now->copy()->addDays(10),
                'status' => true,
                'purchasable' => true,
                'progress' => 'open',
            ],
            // Upcoming/Pending launchpad - starts in future
            [
                'name' => $currencies[5]->name ?? $currencies[1]->name . ' IDO',
                'description' => 'Upcoming initial DEX offering. Get ready to participate in this promising project launch. Mark your calendars for the start date!',
                'currency_id' => $currencies[5]->id ?? $currencies[1]->id,
                'network_id' => $currencies[5]->network_id ?? 6,
                'rate' => '0.02',
                'min_buy' => '50',
                'max_buy' => '10000',
                'soft_cap' => '100000',
                'hard_cap' => '250000',
                'raised_amount' => '0',
                'start_time' => $now->copy()->addDays(7),
                'end_time' => $now->copy()->addDays(21),
                'status' => true,
                'purchasable' => false,
                'progress' => 'pending',
            ],
            // Closed/Completed launchpad - ended successfully
            [
                'name' => $currencies[6]->name ?? $currencies[2]->name . ' Launch',
                'description' => 'This token sale has been completed successfully! Thank you to all participants. Tokens have been distributed to all buyers.',
                'currency_id' => $currencies[6]->id ?? $currencies[2]->id,
                'network_id' => $currencies[6]->network_id ?? 8,
                'rate' => '0.1',
                'min_buy' => '100',
                'max_buy' => '25000',
                'soft_cap' => '200000',
                'hard_cap' => '500000',
                'raised_amount' => '500000',
                'start_time' => $now->copy()->subDays(30),
                'end_time' => $now->copy()->subDays(10),
                'status' => true,
                'purchasable' => false,
                'progress' => 'closed',
            ],
            // Another active launchpad - almost filled
            [
                'name' => $currencies[7]->name ?? $currencies[3]->name . ' Presale',
                'description' => 'Limited presale opportunity! This launchpad is almost sold out. Don\'t miss your chance to participate before it closes.',
                'currency_id' => $currencies[7]->id ?? $currencies[3]->id,
                'network_id' => $currencies[7]->network_id ?? 20,
                'rate' => '0.025',
                'min_buy' => '25',
                'max_buy' => '2500',
                'soft_cap' => '30000',
                'hard_cap' => '75000',
                'raised_amount' => '68000',
                'start_time' => $now->copy()->subDays(3),
                'end_time' => $now->copy()->addDays(4),
                'status' => true,
                'purchasable' => true,
                'progress' => 'open',
            ],
        ];

        foreach ($launchpadData as $data) {
            // Check if launchpad already exists for this currency
            if (!Launchpad::where('currency_id', $data['currency_id'])->exists()) {
                Launchpad::create($data);
            }
        }
    }
}
