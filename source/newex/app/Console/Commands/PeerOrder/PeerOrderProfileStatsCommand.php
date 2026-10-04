<?php

namespace App\Console\Commands\PeerOrder;

use App\Models\Market\Market;
use App\Models\User\User;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use App\Repositories\Order\OrderRepository;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PeerOrderProfileStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'peer-order:profile-stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            // All Trades
            $raw = $this->getTrades(true);
            $rawArray = json_decode(json_encode($raw, true), true);
            User::upsert($rawArray, 'id', ['orders_completed', 'orders_buy_completed', 'orders_sell_completed']);
            $this->info('Updated all-time trade stats');

            // All 30 Trades
            $raw = $this->getTrades(false);
            $rawArray = json_decode(json_encode($raw, true), true);
            User::upsert($rawArray, 'id', ['orders_completed_thirty', 'orders_buy_completed_thirty', 'orders_sell_completed_thirty']);
            $this->info('Updated 30-day trade stats');

            // Completion Rate
            $raw = $this->getCompletionRate(false);
            $rawArray = json_decode(json_encode($raw, true), true);
            User::upsert($rawArray, 'id', ['orders_completion_rate']);
            $this->info('Updated completion rates');

            // Average Pay Time
            $raw = $this->getAveragePaidTime(false);
            $rawArray = json_decode(json_encode($raw, true), true);
            User::upsert($rawArray, 'id', ['orders_avg_paytime']);
            $this->info('Updated average pay times');

            // Average Release Time
            $raw = $this->getAverageReleaseTime(false);
            $rawArray = json_decode(json_encode($raw, true), true);
            User::upsert($rawArray, 'id', ['orders_avg_releasetime']);
            $this->info('Updated average release times');

            return 0;

        } catch (\Throwable $e) {
            Log::error("P2P Profile Stats calculation failed: " . $e->getMessage());
            $this->error("Failed: " . $e->getMessage());
            return 1;
        }
    }

    public function getTrades($all = true) {

        $ordersCompleted = 'orders_completed_thirty';
        $ordersBuyCompleted = 'orders_buy_completed_thirty';
        $ordersSellCompleted = 'orders_sell_completed_thirty';
        $monthFilter = "AND p.created_at > (current_date - interval '30' day)";
        $monthFilterSub = "AND created_at > (current_date - interval '30' day)";

        if($all) {
            $ordersCompleted = 'orders_completed';
            $ordersBuyCompleted = 'orders_buy_completed';
            $ordersSellCompleted = 'orders_sell_completed';
            $monthFilter = '';
            $monthFilterSub = "";
        }

        return DB::select("SELECT u.id, count(p.user_id) as {$ordersCompleted},

(SELECT COUNT(*) FROM peer_orders WHERE status = 'completed' AND ((type = 'buy' AND user_id = u.id) OR (type = 'sell' AND ad_user_id = u.id)) {$monthFilterSub}) as {$ordersBuyCompleted},
(SELECT COUNT(*) FROM peer_orders WHERE status = 'completed' AND ((type = 'sell' AND user_id = u.id) OR (type = 'buy' AND ad_user_id = u.id)) {$monthFilterSub}) as {$ordersSellCompleted},
u.name, u.email, u.password

            FROM users AS u
            INNER JOIN peer_orders AS p
            ON p.user_id = u.id OR p.ad_user_id = u.id
            WHERE p.status = 'completed' {$monthFilter}
            GROUP BY u.id");
    }

    public function getCompletionRate($all = true) {

        $monthFilter = "AND p.created_at > (current_date - interval '30' day)";
        $monthFilterSub = "AND created_at > (current_date - interval '30' day)";

        if($all) {
            $monthFilter = '';
        }

        return DB::select("SELECT u.id,
(1 - ((SELECT COUNT(*) FROM peer_orders WHERE guilty_user_id = u.id AND status != 'completed' AND  (((type = 'buy' AND user_id = u.id) OR (type = 'sell' AND ad_user_id = u.id)) OR ((type = 'sell' AND user_id = u.id) OR (type = 'buy' AND ad_user_id = u.id))) {$monthFilterSub})::NUMERIC /
NULLIF((SELECT COUNT(*) FROM peer_orders WHERE status = 'completed' AND (((type = 'buy' AND user_id = u.id) OR (type = 'sell' AND ad_user_id = u.id)) OR ((type = 'sell' AND user_id = u.id) OR (type = 'buy' AND ad_user_id = u.id))) {$monthFilterSub}), 0)::NUMERIC)) * 100 as orders_completion_rate,
u.name, u.email, u.password

            FROM users AS u
            INNER JOIN peer_orders AS p
            ON p.user_id = u.id OR p.ad_user_id = u.id
            WHERE p.id IS NOT NULL {$monthFilter}
            GROUP BY u.id");
    }

    public function getAveragePaidTime($all = true) {

        $monthFilter = "AND p.created_at > (current_date - interval '30' day)";
        $monthFilterSub = "AND created_at > (current_date - interval '30' day)";

        if($all) {
            $monthFilter = '';
        }

        return DB::select("SELECT u.id,
       (((COALESCE((SELECT SUM(paid_duration) FROM peer_orders WHERE status = 'completed' AND  (type = 'buy' AND user_id = u.id) {$monthFilterSub} GROUP BY user_id), 0)::NUMERIC) +
       (COALESCE((SELECT SUM(paid_duration) FROM peer_orders WHERE status = 'completed' AND  (type = 'sell' AND ad_user_id = u.id) {$monthFilterSub} GROUP BY ad_user_id), 0)::NUMERIC)) /
       (NULLIF((SELECT COUNT(*) FROM peer_orders WHERE status = 'completed' AND  ((type = 'sell' AND ad_user_id = u.id) OR (type = 'buy' AND user_id = u.id)) {$monthFilterSub}), 0)::NUMERIC)) as orders_avg_paytime,
u.name, u.email, u.password

            FROM users AS u
            INNER JOIN peer_orders AS p
            ON p.user_id = u.id OR p.ad_user_id = u.id
            WHERE p.id IS NOT NULL {$monthFilter}
            GROUP BY u.id");
    }

    public function getAverageReleaseTime($all = true) {

        $monthFilter = "AND p.created_at > (current_date - interval '30' day)";
        $monthFilterSub = "AND created_at > (current_date - interval '30' day)";

        if($all) {
            $monthFilter = '';
        }

        return DB::select("SELECT u.id,
       (((COALESCE((SELECT SUM(release_duration) FROM peer_orders WHERE status = 'completed' AND  (type = 'sell' AND user_id = u.id) {$monthFilterSub} GROUP BY user_id), 0)::NUMERIC) +
       (COALESCE((SELECT SUM(release_duration) FROM peer_orders WHERE status = 'completed' AND  (type = 'buy' AND ad_user_id = u.id) {$monthFilterSub} GROUP BY ad_user_id), 0)::NUMERIC)) /
       (NULLIF((SELECT COUNT(*) FROM peer_orders WHERE status = 'completed' AND  ((type = 'buy' AND ad_user_id = u.id) OR (type = 'sell' AND user_id = u.id)) {$monthFilterSub}), 0)::NUMERIC)) as orders_avg_releasetime,
u.name, u.email, u.password

            FROM users AS u
            INNER JOIN peer_orders AS p
            ON p.user_id = u.id OR p.ad_user_id = u.id
            WHERE p.id IS NOT NULL {$monthFilter}
            GROUP BY u.id");
    }
}
