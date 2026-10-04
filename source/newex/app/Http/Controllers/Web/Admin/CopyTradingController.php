<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\CopyTrading\CopyTradingTrader;
use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class CopyTradingController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $userSearch = trim((string) $request->get('user_search', ''));
        $status = $request->get('status');

        $tradersQuery = CopyTradingTrader::query()
            ->with('user:id,email,referral_code,nickname,leader_nickname,is_xn')
            ->withCount([
                'futuresContracts as orders_count',
                'activeFuturesContracts as active_orders_count',
                'activeFollows as followers_count',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('display_name', 'like', '%' . $search . '%')
                        ->orWhere('strategy_label', 'like', '%' . $search . '%')
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('email', 'like', '%' . $search . '%')
                                ->orWhere('referral_code', 'like', '%' . $search . '%')
                                ->orWhere('nickname', 'like', '%' . $search . '%');

                            if (is_numeric($search)) {
                                $userQuery->orWhere('id', (int) $search);
                            }
                        });
                });
            })
            ->when($status === 'enabled', function ($query) {
                $query->where('is_enabled', true);
            })
            ->when($status === 'disabled', function ($query) {
                $query->where('is_enabled', false);
            })
            ->orderBy('sort_order')
            ->orderByDesc('id');

        $perPage = min(max((int) $request->get('per_page', 20), 10), 100);

        $traders = $tradersQuery->paginate($perPage)
            ->withQueryString();

        $traders->getCollection()->transform(function (CopyTradingTrader $trader) {
            return [
                'id' => $trader->id,
                'publication_revision' => (int)$trader->publication_revision,
                'publication_status' => $trader->publication_status,
                'publication_reference' => $trader->publication_reference,
                'user_id' => $trader->user_id,
                'display_name' => $trader->display_name,
                'display_title' => $trader->displayName(),
                'strategy_label' => $trader->strategy_label,
                'history_orders_count' => (int) $this->traderValue($trader, 'history_orders_count', 0),
                'win_rate' => $this->decimal($this->traderValue($trader, 'win_rate', 0), 2),
                'section_label' => $this->traderValue($trader, 'section_label', '') ?: '高盈亏',
                'display_followers_count' => (int) $this->traderValue($trader, 'display_followers_count', 0),
                'display_followers_limit' => (int) $this->traderValue($trader, 'display_followers_limit', 0),
                'display_badges' => $this->traderValue($trader, 'display_badges', ''),
                'display_profit_amount' => $this->decimal($this->traderValue($trader, 'display_profit_amount', 0), 2),
                'display_roi_percent' => $this->decimal($this->traderValue($trader, 'display_roi_percent', 0), 2),
                'display_asset_scale' => $this->decimal($this->traderValue($trader, 'display_asset_scale', 0), 2),
                'display_max_drawdown' => $this->decimal($this->traderValue($trader, 'display_max_drawdown', 0), 2),
                'display_lead_days' => (int) $this->traderValue($trader, 'display_lead_days', 0),
                'display_chart_points' => $this->traderValue($trader, 'display_chart_points', ''),
                'sort_order' => $trader->sort_order,
                'is_enabled' => $trader->is_enabled,
                'orders_count' => (int) ($trader->orders_count ?? 0),
                'active_orders_count' => (int) ($trader->active_orders_count ?? 0),
                'followers_count' => (int) ($trader->followers_count ?? 0),
                'created_at' => optional($trader->created_at)->format('Y-m-d H:i:s'),
                'updated_at' => optional($trader->updated_at)->format('Y-m-d H:i:s'),
                'user' => $trader->user ? [
                    'id' => $trader->user->id,
                    'email' => $trader->user->email,
                    'referral_code' => $trader->user->referral_code,
                    'nickname' => $trader->user->nickname,
                    'leader_nickname' => $trader->user->leader_nickname,
                    'is_xn' => (bool) $trader->user->is_xn,
                ] : null,
            ];
        });

        $userOptions = $this->getUserOptions($userSearch);

        return Inertia::render('Admin/CopyTrading/Index', [
            'filters' => $request->all(['search', 'user_search', 'status', 'per_page']),
            'traders' => $traders,
            'userOptions' => $userOptions,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateTrader($request);
        $request->validate(['user_id' => 'unique:copy_trading_traders,user_id']);

        \App\Services\Content\ProductPublication::save($request, function ($publication) use ($request, $data) {
        return CopyTradingTrader::create(
            array_merge($data, $publication, [
                'created_by' => optional($request->user())->id,
            ])
        );
        });

        return Redirect::back()->with('success', __('跟单展示用户已保存。'));
    }

    public function update(Request $request, CopyTradingTrader $copyTrader)
    {
        $data = $this->validateTrader($request, false);

        \App\Services\Content\ProductPublication::save($request, function ($publication) use ($copyTrader, $data) {
            $copyTrader->update(array_merge($data, $publication));
            return $copyTrader->refresh();
        }, $copyTrader);

        return Redirect::back()->with('success', __('跟单展示用户已更新。'));
    }

    public function destroy(CopyTradingTrader $copyTrader)
    {
        $copyTrader->delete();

        return Redirect::back()->with('success', __('跟单展示用户已删除。'));
    }

    protected function validateTrader(Request $request, bool $requireUser = true): array
    {
        $rules = [
            'display_name' => ['nullable', 'string', 'max:120'],
            'strategy_label' => ['nullable', 'string', 'max:120'],
            'history_orders_count' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'win_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'section_label' => ['nullable', 'string', 'max:40'],
            'display_followers_count' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'display_followers_limit' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'display_badges' => ['nullable', 'string', 'max:255'],
            'display_profit_amount' => ['nullable', 'numeric', 'min:-999999999999999999.99', 'max:999999999999999999.99'],
            'display_roi_percent' => ['nullable', 'numeric', 'min:-99999999.99', 'max:99999999.99'],
            'display_asset_scale' => ['nullable', 'numeric', 'min:0', 'max:999999999999999999.99'],
            'display_max_drawdown' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'display_lead_days' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'display_chart_points' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'is_enabled' => ['nullable', 'boolean'],
        ];

        if ($requireUser) {
            $rules['user_id'] = ['required', 'integer', 'exists:users,id'];
        }

        $data = $request->validate($rules);

        $data['display_name'] = trim((string) ($data['display_name'] ?? ''));
        $data['strategy_label'] = trim((string) ($data['strategy_label'] ?? ''));
        $data['history_orders_count'] = (int) ($data['history_orders_count'] ?? 0);
        $data['win_rate'] = $this->decimal($data['win_rate'] ?? 0, 2);
        $data['section_label'] = trim((string) ($data['section_label'] ?? ''));
        $data['display_followers_count'] = (int) ($data['display_followers_count'] ?? 0);
        $data['display_followers_limit'] = (int) ($data['display_followers_limit'] ?? 0);
        $data['display_badges'] = trim((string) ($data['display_badges'] ?? ''));
        $data['display_profit_amount'] = $this->decimal($data['display_profit_amount'] ?? 0, 2);
        $data['display_roi_percent'] = $this->decimal($data['display_roi_percent'] ?? 0, 2);
        $data['display_asset_scale'] = $this->decimal($data['display_asset_scale'] ?? 0, 2);
        $data['display_max_drawdown'] = $this->decimal($data['display_max_drawdown'] ?? 0, 2);
        $data['display_lead_days'] = (int) ($data['display_lead_days'] ?? 0);
        $data['display_chart_points'] = trim((string) ($data['display_chart_points'] ?? ''));
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_enabled'] = filter_var($data['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        foreach ($this->optionalColumns() as $column) {
            if (!Schema::hasColumn('copy_trading_traders', $column)) {
                unset($data[$column]);
            }
        }

        return $data;
    }

    protected function decimal($value, int $precision = 2): string
    {
        return number_format((float) ($value ?: 0), $precision, '.', '');
    }

    protected function optionalColumns(): array
    {
        return [
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
        ];
    }

    protected function traderValue(CopyTradingTrader $trader, string $column, $default = null)
    {
        if (!array_key_exists($column, $trader->getAttributes())) {
            return $default;
        }

        return $trader->getAttribute($column);
    }

    protected function getUserOptions(string $search): array
    {
        return User::query()
            ->select(['id', 'email', 'referral_code', 'nickname', 'leader_nickname', 'is_xn'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('email', 'like', '%' . $search . '%')
                        ->orWhere('referral_code', 'like', '%' . $search . '%')
                        ->orWhere('nickname', 'like', '%' . $search . '%')
                        ->orWhere('leader_nickname', 'like', '%' . $search . '%');

                    if (is_numeric($search)) {
                        $query->orWhere('id', (int) $search);
                    }
                });
            })
            ->orderByDesc('id')
            ->limit(80)
            ->get()
            ->map(function (User $user) {
                $account = $user->email ?: ($user->referral_code ?: ('UID ' . $user->id));
                $name = $user->nickname ?: $user->leader_nickname;

                return [
                    'id' => $user->id,
                    'label' => trim($account . ($name ? ' / ' . $name : '')),
                    'email' => $user->email,
                    'referral_code' => $user->referral_code,
                    'nickname' => $user->nickname,
                    'leader_nickname' => $user->leader_nickname,
                    'is_xn' => (bool) $user->is_xn,
                ];
            })
            ->values()
            ->toArray();
    }
}
