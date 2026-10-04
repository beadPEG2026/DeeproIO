<?php

namespace App\Console\Commands\Launchpad;

use App\Models\Launchpad\Launchpad;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LaunchpadStateMonitorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'launchpad:state-monitor';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor and update launchpad states based on time and caps';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $now = Carbon::now();
        $updated = 0;

        // Get active launchpads that might need status updates
        $launchpads = Launchpad::where('status', true)
            ->where('progress', '!=', 'closed')
            ->get();

        foreach ($launchpads as $launchpad) {
            try {
                DB::beginTransaction();

                // Lock the launchpad record
                $launchpad = Launchpad::where('id', $launchpad->id)->lockForUpdate()->first();

                if (!$launchpad || !$launchpad->status) {
                    DB::rollBack();
                    continue;
                }

                $started = $launchpad->start_time->lessThanOrEqualTo($now);
                $finished = $launchpad->end_time->lessThanOrEqualTo($now);
                $hardCapReached = math_compare($launchpad->raised_amount, $launchpad->hard_cap) >= 0;

                $needsUpdate = false;

                // Pending -> Open: Start time has passed, end time hasn't
                if ($launchpad->progress === "pending" && $started && !$finished && !$hardCapReached) {
                    $launchpad->progress = "open";
                    $launchpad->purchasable = true;
                    $needsUpdate = true;
                }
                // Open -> Closed: End time passed OR hard cap reached
                elseif ($launchpad->progress === "open" && ($finished || $hardCapReached)) {
                    $launchpad->progress = "closed";
                    $launchpad->purchasable = false;
                    $needsUpdate = true;
                }
                // Pending -> Closed: End time passed without ever opening (edge case)
                elseif ($launchpad->progress === "pending" && $finished) {
                    $launchpad->progress = "closed";
                    $launchpad->purchasable = false;
                    $needsUpdate = true;
                }

                if ($needsUpdate) {
                    $launchpad->save();
                    $updated++;
                }

                DB::commit();

            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error("Error updating launchpad state for {$launchpad->id}: " . $e->getMessage());
            }
        }

        if ($updated > 0) {
            $this->info("Updated {$updated} launchpad(s)");
        }

        return 0;
    }
}
