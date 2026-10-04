<?php
namespace Tests\Fixtures;

use App\Http\Controllers\Web\Admin\MarketController;
use App\Http\Requests\Web\Market\MarketFormRequest;
use App\Models\Market\MarketAdmin;
use Illuminate\Support\Facades\{DB, Redirect};

/** User-proposed update method, exercised only in the isolated test application. */
final class MarketUpdateProposalController extends MarketController
{
    public function update(MarketFormRequest $request, MarketAdmin $market)
    {
        $data = $request->validated();
        $this->sleepWhenSubmitHitsMinuteBoundary();

        $table = $market->getTable();
        $primaryKey = $market->getKeyName();
        $marketId = $market->getKey();

        $dbMarket = DB::table($table)
            ->where($primaryKey, $marketId)
            ->first();

        if (!$dbMarket) {
            return Redirect::route('admin.markets')
                ->with('error', 'Market not found.');
        }

        /*
         * 编辑市场时，不允许后台表单更新 last。
         * last 应该由行情、K线、机器人逻辑更新。
         */
        unset($data['last']);

        /*
         * 兼容两种提交结构：
         * 1. bot_price_floor
         * 2. market.bot_price_floor
         */
        if ($request->has('bot_price_floor')) {
            $botPriceFloor = (float) $request->input('bot_price_floor');
        } elseif ($request->has('market.bot_price_floor')) {
            $botPriceFloor = (float) $request->input('market.bot_price_floor');
        } else {
            $botPriceFloor = (float) ($dbMarket->bot_price_floor ?? 0);
        }

        /*
         * 限制百分比范围：
         * 最大 100
         * 最小 -100
         */
        if ($botPriceFloor > 100) {
            $botPriceFloor = 100;
        }

        if ($botPriceFloor < -100) {
            $botPriceFloor = -100;
        }

        /*
         * 防止浮点数出现 0.0000000001 这种幽灵小数。
         */
        if (abs($botPriceFloor) < 0.00000001) {
            $botPriceFloor = 0;
        }

        /*
         * 这些字段不要跟随普通字段一起 save，
         * 防止被表单里的旧值覆盖。
         */
        unset($data['bot_price_floor']);
        unset($data['bot_price_ceiling']);
        unset($data['custom_liquidity_t']);

        if (isset($data['market']) && is_array($data['market'])) {
            unset($data['market']['last']);
            unset($data['market']['bot_price_floor']);
            unset($data['market']['bot_price_ceiling']);
            unset($data['market']['custom_liquidity_t']);
        }

        /*
         * 先保存普通字段。
         * 注意：这里不会保存 last。
         */
        $market->forceFill($data);
        $market->save();
        $market->refresh();

        $this->applyKlinePriceAdjustment($market, $botPriceFloor, $dbMarket);
        $this->marketService->updateMarketsInfoCache();

        return Redirect::back()
            ->with('success', 'Target Exchange updated.');
    }

}
