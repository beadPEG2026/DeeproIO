<?php
namespace App\Console\Commands\Market;
use App\Models\Market\Market;
use App\Models\Order\Order;
use App\Services\Market\{StockAssets,StockLiquidity};
use App\Repositories\Order\OrderRepository;
use App\Events\OrderBookSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
final class StockLiquidityCommand extends Command {
    protected $signature='market:stock-liquidity {market} {--once}';
    protected $description='Refresh verified Alpha depth for internal stock-token matching';
    public function handle(StockLiquidity $liquidity): int {
        $market=Market::whereName($this->argument('market'))->firstOrFail();
        if (!StockAssets::supports($market->name)) { $this->error('Not a registered stock market'); return 1; }
        do {
            $market->refresh();
            if (!$market->liq || !$market->status || !$market->trade_status) return 0;
            try {
                $liquidity->refresh($market);
                $book=$liquidity->levels($market); $repo=new OrderRepository();
                foreach (['bids'=>'buy','asks'=>'sell'] as $key=>$side) {
                    $book[$key]=collect($book[$key])->merge($repo->get($market->name,$side)->map(fn($o)=>['price'=>$o->price,'quantity'=>$o->quantity]))->groupBy('price')->map(fn($rows)=>['price'=>$rows->first()['price'],'quantity'=>$rows->reduce(fn($sum,$r)=>bcadd($sum,(string)$r['quantity'],18),'0')]);
                    $book[$key]=($key==='bids'?$book[$key]->sortByDesc('price'):$book[$key]->sortBy('price'))->values();
                }
                broadcast(new OrderBookSnapshot($market->name,$book['bids'],$book['asks'],$market));
            } catch (\Throwable $e) {
                Log::warning('Stock depth refresh failed',['market'=>$market->name,'reason'=>$e->getMessage()]);
                // Expiry is enforced by the matching service and HTTP book, even if this worker exits.
                if ($this->option('once')) { $this->error($e->getMessage()); return 1; }
            }
            if ($this->option('once')) return 0;
            sleep(3);
        } while (true);
    }
}
