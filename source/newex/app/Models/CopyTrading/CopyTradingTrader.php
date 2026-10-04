<?php

namespace App\Models\CopyTrading;

use App\Models\Order\FuturesContract;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

class CopyTradingTrader extends Model
{
    protected $fillable = [
        'publication_status', 'publication_reference', 'publication_reviewed_by', 'publication_reviewed_at',
        'user_id',
        'display_name',
        'strategy_label',
        'history_orders_count',
        'win_rate',
        'section_label',
        'display_followers_count',
        'display_followers_limit',
        'display_badges',
        'display_profit_amount',
        'display_roi_percent',
        'display_asset_scale',
        'display_max_drawdown',
        'display_lead_days',
        'display_chart_points',
        'sort_order',
        'is_enabled',
        'created_by',
    ];

    public function scopePublished($query)
    {
        return $query->where('publication_status', 'published')
            ->whereNotNull('publication_reviewed_by')->whereNotNull('publication_reviewed_at');
    }

    public function isPublished(): bool
    {
        return $this->publication_status === 'published' && $this->publication_reviewed_by !== null && $this->publication_reviewed_at !== null;
    }

    protected $casts = [
        'history_orders_count' => 'integer',
        'win_rate' => 'decimal:2',
        'display_followers_count' => 'integer',
        'display_followers_limit' => 'integer',
        'display_profit_amount' => 'decimal:2',
        'display_roi_percent' => 'decimal:2',
        'display_asset_scale' => 'decimal:2',
        'display_max_drawdown' => 'decimal:2',
        'display_lead_days' => 'integer',
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function futuresContracts()
    {
        return $this->hasMany(FuturesContract::class, 'user_id', 'user_id');
    }

    public function activeFuturesContracts()
    {
        return $this->futuresContracts()->where('status', 'active');
    }

    public function follows()
    {
        return $this->hasMany(CopyTradingFollow::class, 'copy_trading_trader_id');
    }

    public function activeFollows()
    {
        return $this->follows()->where('is_enabled', true);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function displayName(): string
    {
        $name = trim((string) $this->display_name);

        if ($name !== '') {
            return $name;
        }

        $user = $this->user;

        return (string) (
            optional($user)->nickname
            ?: optional($user)->leader_nickname
            ?: optional($user)->referral_code
            ?: optional($user)->email
            ?: ('Trader #' . $this->user_id)
        );
    }
}
