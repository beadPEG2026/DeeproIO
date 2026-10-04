<?php

namespace App\Repositories;

use App\Models\FundingFeeDistribution;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;

class FundingFeeDistributionRepository
{
    public function getReport()
    {
        $distributions = FundingFeeDistribution::query();

        $distributions->with(['user', 'market.quoteCurrency', 'market.baseCurrency', 'futuresContract']);

        // Filter by user if provided
        if (request()->has('user_id') && request()->get('user_id')) {
            $distributions->where('user_id', request()->get('user_id'));
        }

        // Filter by market if provided
        if (request()->has('market') && request()->get('market')) {
            $marketParam = request()->get('market');
            if (is_numeric($marketParam)) {
                $distributions->where('market_id', $marketParam);
            } else {
                $distributions->whereHas('market', function($q) use ($marketParam) {
                    $q->where('name', $marketParam);
                });
            }
        }

        // Filter by date range if provided
        if (request()->has('start_date') && request()->get('start_date')) {
            $distributions->where('distributed_at', '>=', request()->get('start_date'));
        }

        if (request()->has('end_date') && request()->get('end_date')) {
            $distributions->where('distributed_at', '<=', request()->get('end_date'));
        }

        $distributions->orderBy('distributed_at', 'desc');

        return $distributions->paginate(50)->withQueryString();
    }

    public function getReportForUser(User $user, $limit = 50)
    {
        $distributions = FundingFeeDistribution::query();

        $distributions->where('user_id', $user->id);

        $distributions->with(['market.quoteCurrency', 'market.baseCurrency', 'futuresContract']);

        // Filter by market if provided
        if (request()->has('market') && request()->get('market')) {
            $marketParam = request()->get('market');
            if (is_numeric($marketParam)) {
                $distributions->where('market_id', $marketParam);
            } else {
                $distributions->whereHas('market', function($q) use ($marketParam) {
                    $q->where('name', $marketParam);
                });
            }
        }

        // Filter by date range if provided
        if (request()->has('start_date') && request()->get('start_date')) {
            $distributions->where('distributed_at', '>=', request()->get('start_date'));
        }

        if (request()->has('end_date') && request()->get('end_date')) {
            $distributions->where('distributed_at', '<=', request()->get('end_date'));
        }

        $distributions->orderBy('distributed_at', 'desc');

        return $distributions->paginate($limit)->withQueryString();
    }

    public function getStatReport($period)
    {
        return DB::table('funding_fee_distributions')
            ->selectRaw('markets.name as pair, currencies.symbol, 
                SUM(CASE WHEN funding_fee_amount > 0 THEN funding_fee_amount ELSE 0 END) as total_collected,
                SUM(CASE WHEN funding_fee_amount < 0 THEN ABS(funding_fee_amount) ELSE 0 END) as total_paid,
                COUNT(*) as total_distributions')
            ->join('markets', 'markets.id', 'funding_fee_distributions.market_id')
            ->join('currencies', 'currencies.id', 'markets.quote_currency_id')
            ->whereBetween('funding_fee_distributions.distributed_at', $period)
            ->groupByRaw('markets.name, currencies.symbol')
            ->get();
    }
}
