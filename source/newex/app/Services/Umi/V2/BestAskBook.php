<?php
namespace App\Services\Umi\V2;

use App\Models\Market\Market;
use App\Services\Market\FundedLiquidity;

/** The same funded and real-order book exposed by the Deepro trading API. */
class BestAskBook
{
    public function read(Market $market): array
    {
        return app(FundedLiquidity::class)->publicBook($market);
    }
}
