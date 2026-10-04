<?php

namespace App\Models\User;

use App\Jobs\QueuedVerifyEmailJob;
use App\Models\User\Traits\Relations\UserRelation;
use App\Models\User\Traits\Scopes\UserScope;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;
    use HasRoles;
    use UserRelation;
    use UserScope;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'referral_id',
        'referral_code',
        'deactivated',
        'withdrawal_disabled',
        'email_verified_at',
        'kyc_verified_at',
        'deleted',
        'google_id',
        'twitter_id',
        'is_online',
        'nickname',
        'last_seen_at',
        'p2p_trade_ban',
        'excluded_from_transfer_fee',
        'first_trading_deposit_usd',
        'cumulative_earnings_usd',
        'is_t',
        'is_xn',
        'is_xm',
        'leader_nickname',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
        'current_team_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime:Y-m-d H:i:s',
        'kyc_verified_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'deactivated' => 'boolean',
        'withdrawal_disabled' => 'boolean',
        'p2p_trade_ban' => 'boolean',
        'excluded_from_transfer_fee' => 'boolean',
        'is_t' => 'boolean',
        'is_xn' => 'boolean',
        'is_xm' => 'boolean',
        'last_seen_at' => 'datetime:Y-m-d H:i:s',
        'peer_username_updated_at' => 'datetime:Y-m-d H:i:s',
        'two_factor_confirmed_at' => 'datetime:Y-m-d H:i:s',
        'first_trading_deposit_usd' => 'decimal:18',
        'cumulative_earnings_usd' => 'decimal:18',
    ];

    /**
     * 注意：
     * nickname 是数据库真实字段，不要放进 appends。
     * 否则如果存在 getNicknameAttribute，会覆盖真实字段。
     */
    protected $appends = [
        'email_verified',
        'kyc_verified',
        'is_online',
    ];

    public function getEmailVerifiedAttribute()
    {
        return (bool) $this->email_verified_at;
    }

    public function getKycVerifiedAttribute()
    {
        return (bool) $this->kyc_verified_at;
    }

    public function getIsOnlineAttribute($value)
    {
        if (!$this->last_seen_at) {
            return false;
        }

        return now()->diffInMinutes(Carbon::parse($this->last_seen_at)) >= 10 ? false : true;
    }

    public function sendEmailVerificationNotification()
    {
        QueuedVerifyEmailJob::dispatch($this);
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => str_contains($value, 'temp-email.loc') ? '' : $value,
        );
    }
}
