<?php

namespace App\Http\Middleware;

use App\Services\Language\LanguageService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;
use Setting;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public function version(Request $request)
    {
        // Different entry bundles must trigger a full visit when crossing the
        // auth/exchange boundary, including server-side registration redirects.
        if ($request->is('login', 'register', 'forgot-password', 'reset-password/*', 'two-factor-challenge', 'exchange-control-panel/admin-login')) {
            $manifest = public_path('auth/mix-manifest.json');
            return 'auth-' . (is_file($manifest) ? md5_file($manifest) : 'pending');
        }
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function share(Request $request)
    {
        $darkModeEnabled = (bool) setting('general.dark_mode_status', false);
        $langModeEnabled = (bool) setting('general.language_status', false);
        $defaultTheme = $darkModeEnabled && (bool) setting('general.default_dark_mode_status', false)
            ? 'dark' : 'light';
        $theme = is_mobile_instance() ? 'dark' : Cookie::get('theme', $defaultTheme);

        $defaultTradePair = Setting::get('general.default_trade_pair', 'BTC-USDT');
        $defaultFuturesPair = Setting::get('general.default_futures_pair', 'BTC-USDT');

        $routeName = $request->route() ? $request->route()->getName() : null;

        return array_merge(parent::share($request), [
            'timezone' => config('app.timezone'),
            'displayCurrencies' => fn () => app(\App\Services\Market\DisplayExchangeRates::class)->options(),
            'depositLocalPreview' => app()->environment('local'),
            'broadcast' => config('broadcasting.public') + ['key'=>config('broadcasting.connections.pusher.key'),'cluster'=>config('broadcasting.connections.pusher.options.cluster')],
            'umiBusinessLocal' => app()->environment(['local','testing']) && (bool)config('umi-business.enabled'),
            'umiV2Local' => app()->environment(['local','testing']) && (bool)config('umi-v2.local_acceptance'),
            'umiFundedVisible' => (bool) config('umi-v2.funded_enabled'),
            'umiBusinessVisible' => app(\App\Services\Umi\Business\Rules::class)->readable(),
            'jetstream' => null,
            'session' => $request->session()->get('flash', null),
            'live_conenction' => !Setting::get('general.maintenance_status', false),
            'adminAllowedRoutes' => fn () => \App\Support\AdminAccess::routes($request->user()),
            'siteNavigation' => fn()=>in_array($routeName,['login','register','password.request','password.reset'],true)?null:app(\App\Services\Content\SitePresentation::class)->navigation(),
            'siteLogo' => setting('general.logo') ?: '/images/deepro-logo.svg',
            'siteName' => config('app.name', 'Deepro'),
            'merchant' => config('merchant_acquiring.enabled'),
            'lending' => config('app.lending'),
            'v' => intval(config('app.plan')),
            'social' => setting('social'),
            'languages' => (new LanguageService())->getLanguages(),
            'captcha' => (new SettingsService())->getCaptchaStatus(),
            'captcha_key' => config('captcha.sitekey'),
            'alt' => is_mobile_instance(),
            'isHome' => $routeName === 'home',
            'isMarket' => $routeName === 'market' || $routeName === 'market.lite',
            'theme' => $theme === 'dark' ? 'dark' : 'light',
            'theme_mode_enabled' => $darkModeEnabled,
            'lang_mode_enabled' => $langModeEnabled,
            'referral_code' => request()->get('referral', false),
            'referral_code_required' => Setting::get('general.registration_referral_required', false),
            'mode' => config('app.readonly') ? 'readonly' : '',
            'defaultTradePair' => $defaultTradePair,
            'defaultFuturesPair' => $defaultFuturesPair,
            'unlim_base_pair' => config('unlim.default_base_pair'),
            'unlim_quote_pair' => config('unlim.default_quote_pair'),
            'unlim_status' => config('unlim.enabled', false),
            'wallet_connect_enabled' => config('app.wallet_connect_enabled', true),
            'twitter_auth_enabled' => config('app.twitter_auth_enabled', true),
            'admin_pending_reviews' => function () use ($request) {
                return $this->getAdminPendingReviewCounts($request);
            },

            'user' => function () use ($request) {
                if (!$request->user()) {
                    return null;
                }

                $authUser = $request->user();
                $authUser->loadMissing('roles');

                $roles = $authUser->roles
                    ->pluck('name')
                    ->filter()
                    ->values()
                    ->toArray();

                $username = $authUser->peer_username;
                $username = mask_nickname($username, $authUser->email);

                /*
                 * mask_nickname 有些情况下会返回数组。
                 * 这里统一整理成字符串，避免 mb_substr 报：
                 * mb_substr(): Argument #1 ($string) must be of type string, array given
                 */
                if (is_array($username)) {
                    $username = $username['nickname']
                        ?? $username['name']
                        ?? $username['username']
                        ?? $username['email']
                        ?? reset($username)
                        ?? '';
                }

                if (is_array($username) || is_object($username)) {
                    $username = '';
                }

                $username = is_scalar($username) ? (string) $username : '';
                $username = trim($username);

                $masked = $username !== '' ? $username : '';
                $name = $authUser->name;

                if (!$authUser->email) {
                    $walletId = (string) ($authUser->wallet_id ?? '');

                    if ($walletId !== '') {
                        $masked = mb_substr($walletId, 0, 5) . "......" . mb_substr($walletId, -3);
                        $name = $masked;
                    }
                }

                /*
                 * 四种后台身份：
                 * superadmin  => 總後台
                 * admin       => 後台
                 * user_leader => 組長
                 * salesman    => 業務員
                 *
                 * 只要拥有以上任意身份，或者拥有 perm_ 开头的后台功能权限，
                 * 前台“我的”里面就显示 Admin Dashboard 入口。
                 */
                $adminIdentityRoles = [
                    'superadmin',
                    'admin',
                    'user_leader',
                    'salesman',
                ];

                $hasAdminIdentity = !empty(array_intersect($roles, $adminIdentityRoles));

                $hasAdminPermission = collect($roles)->contains(function ($role) {
                    return is_string($role) && strpos($role, 'perm_') === 0;
                });

                $canAccessAdmin = \App\Support\AdminAccess::allows($authUser);

                return [
                    'id' => $authUser->id,
                    'email' => $authUser->email,
                    'nickname' => $masked,
                    'name' => $name,
                    'referral_code' => $authUser->referral_code,
                    'kyc_verified' => $authUser->kyc_verified,
                    'merchant_verified' => $authUser->merchant_verified_at ? true : false,
                    'bank_verified' => $authUser->bank_verified,
                    'first_trading_deposit_usd' => $authUser->first_trading_deposit_usd ?? 0,
                    'cumulative_earnings_usd' => $authUser->cumulative_earnings_usd ?? 0,
                    'is_xn' => (bool) ($authUser->is_xn ?? false),
                    'is_xm' => (bool) ($authUser->is_xm ?? false),

                    /*
                     * 兼容你前端 App.template 里的：
                     * $page.props.user.admin
                     */
                    'admin' => $canAccessAdmin,

                    /*
                     * 新字段，后面前端可以直接用：
                     * $page.props.user.can_access_admin
                     */
                    'can_access_admin' => $canAccessAdmin,
                    'can_access_custody' => \App\Services\Custody\CustodyAccess::allowed($authUser),

                    /*
                     * 给前端完整角色列表。
                     */
                    'roles' => $roles,

                    'two_factor_enabled' => $authUser->hasEnabledTwoFactorAuthentication(),
                ];
            },
        ]);
    }

    protected function getAdminPendingReviewCounts(Request $request): array
    {
        $empty = [
            'kyc_pending_count' => 0,
            'bank_pending_count' => 0,
            'total' => 0,
        ];

        if (!$request->is('exchange-control-panel*') || !$request->user()) {
            return $empty;
        }

        $authUser = $request->user();
        $authUser->loadMissing('roles');

        $roleNames = $authUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();

        $roleIds = $authUser->roles
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        $canSeeKyc = $this->hasAnyAdminReviewRole($roleNames, [
            'superadmin',
            'admin',
            'user_editor',
            'perm_kyc_documents',
        ]) || (auth()->user()?->hasRole('superadmin') ?? false);

        $canSeeBank = $this->hasAnyAdminReviewRole($roleNames, [
            'superadmin',
            'admin',
            'finance_manager',
            'perm_bank_accounts',
        ]) || (auth()->user()?->hasRole('superadmin') ?? false);

        $scopeUserIds = $this->resolveAdminReviewScopeUserIds($authUser, $roleNames, $roleIds);

        $kycCount = $canSeeKyc
            ? $this->countPendingKycDocuments($scopeUserIds)
            : 0;

        $bankCount = $canSeeBank
            ? $this->countPendingBankAccounts($scopeUserIds)
            : 0;

        return [
            'kyc_pending_count' => $kycCount,
            'bank_pending_count' => $bankCount,
            'total' => $kycCount + $bankCount,
        ];
    }

    protected function hasAnyAdminReviewRole(array $roleNames, array $allowedRoles): bool
    {
        return count(array_intersect($roleNames, $allowedRoles)) > 0;
    }

    protected function resolveAdminReviewScopeUserIds($authUser, array $roleNames, array $roleIds)
    {
        if (in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false)) {
            return null;
        }

        $teamScopeRoles = [
            'admin',
            'user_leader',
            'salesman',
            'user_editor',
            'finance_manager',
            'perm_users',
            'perm_kyc_documents',
            'perm_bank_accounts',
        ];

        if (!$this->hasAnyAdminReviewRole($roleNames, $teamScopeRoles) && !(auth()->user()?->hasRole('admin') ?? false)) {
            return [];
        }

        return $this->getAllAdminReviewTeamUserIds((int) $authUser->id);
    }

    protected function getAllAdminReviewTeamUserIds(int $userId): array
    {
        if (!Schema::hasTable('users')) {
            return [];
        }

        $allIds = [$userId];
        $pendingIds = [$userId];

        while (!empty($pendingIds)) {
            $children = DB::table('users')
                ->whereIn('referral_id', $pendingIds)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();

            $children = array_values(array_diff($children, $allIds));

            if (empty($children)) {
                break;
            }

            $allIds = array_merge($allIds, $children);
            $pendingIds = $children;
        }

        return array_values(array_unique(array_map('intval', $allIds)));
    }

    protected function countPendingKycDocuments($scopeUserIds): int
    {
        if (!Schema::hasTable('kyc_documents')) {
            return 0;
        }

        $query = DB::table('kyc_documents')->where('status', 'pending');

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return 0;
            }

            $query->whereIn('user_id', $scopeUserIds);
        }

        return (int) $query->count();
    }

    protected function countPendingBankAccounts($scopeUserIds): int
    {
        if (!Schema::hasTable('bank_accounts')) {
            return 0;
        }

        $query = DB::table('bank_accounts');
        $userColumn = null;

        foreach (['user_id', 'uid', 'client_id'] as $column) {
            if (Schema::hasColumn('bank_accounts', $column)) {
                $userColumn = $column;
                $query->where($column, '>', 0);
                break;
            }
        }

        if (Schema::hasColumn('bank_accounts', 'status_type')) {
            $query->where('status_type', 0);
        } elseif (Schema::hasColumn('bank_accounts', 'status')) {
            $query->where('status', false);
        } else {
            return 0;
        }

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds) || !$userColumn) {
                return 0;
            }

            $query->whereIn($userColumn, $scopeUserIds);
        }

        return (int) $query->count();
    }
}
