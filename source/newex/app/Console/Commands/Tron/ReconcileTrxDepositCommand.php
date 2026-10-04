<?php
namespace App\Console\Commands\Tron;
use App\Models\Wallet\WalletAddress;
use App\Services\Deposit\VerifiedTrxDeposit;
use Illuminate\Console\Command;

class ReconcileTrxDepositCommand extends Command
{
    protected $signature = 'tron:reconcile-deposit {hash} {--address-id=} {--apply : Credit once after solidified receipt verification} {--public-read : Explicit public read for this one hash only; never a scanner fallback}';
    protected $description = 'Verify one reported TRX deposit; dry run unless --apply is provided';
    public function handle(VerifiedTrxDeposit $service): int
    {
        try {
            $address = WalletAddress::whereIn('network_id', [NETWORK_TRX, NETWORK_TRC])->findOrFail($this->option('address-id'));
            $result = $service->process($address, $this->argument('hash'), (bool)$this->option('public-read'), (bool)$this->option('apply'));
            $this->line(json_encode($result));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(preg_match('/^TRON[A-Z0-9_]+$/', $e->getMessage()) ? $e->getMessage() : 'TRON_RECONCILE_FAILED');
            return self::FAILURE;
        }
    }
}
