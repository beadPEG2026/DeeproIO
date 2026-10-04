<?php
namespace App\Console\Commands\Staking;
class ApplyStakingConfiguration extends \Illuminate\Console\Command {
    protected $signature='staking:apply-configuration';
    protected $description='Apply due audited product settings to new subscriptions only';
    public function handle(\App\Services\Operations\StakingConfiguration $settings):int {$this->info('Applied: '.$settings->applyDue());return 0;}
}
