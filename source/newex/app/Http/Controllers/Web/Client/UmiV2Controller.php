<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Services\Umi\V2\FundedDashboard;
use App\Services\Umi\V2\FundedIntake;
use App\Services\Umi\V2\FundedWithdrawal;
use App\Services\Umi\V2\StockShares;
use App\Services\Umi\V2\FundedRuntime;
use App\Models\Umi\LegacyAccount;
use App\Services\Umi\LegacyPortfolio;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

final class UmiV2Controller extends Controller
{
    public function index(Request $request)
    {
        $query = $request->validate([
            'state_only'=>'nullable|boolean',
            'legacy_section' => 'nullable|in:overview,burn,team-income,usdt,team-usdt,team,treasury,bao,vip',
            'page' => 'nullable|integer|min:1|max:100000',
            'history_entries_page' => 'nullable|integer|min:1|max:100000',
            'tab' => 'nullable|in:history,home,join,income,team,transfer,withdraw,progress,burn,points,stocks,mine,records',
        ]);
        $userId = (int) $request->user()->id;
        if (Schema::hasTable('umi_v2_invite_aliases')) {
            $memberId = \Illuminate\Support\Facades\DB::table('umi_v2_members')->where('user_id',$userId)->value('id');
            if ($memberId) app(\App\Services\Umi\V2\ShortInviteCode::class)->forMember((int)$memberId);
        }
        if ($request->boolean('state_only')) return response()->json(['state'=>app(FundedDashboard::class)->member($userId)])->header('Cache-Control','private, no-store');
        $legacy = Schema::hasTable('umi_legacy_accounts')
            ? LegacyAccount::where('user_id', $userId)->first() : null;
        return Inertia::render('Umi/FundedHome', [
            'state' => app(FundedDashboard::class)->member($userId),
            'legacy' => $legacy ? app(LegacyPortfolio::class)->show($legacy,
                $query['legacy_section'] ?? 'overview', (int) ($query['page'] ?? 1)) : null,
            'legacySection' => $query['legacy_section'] ?? 'overview',
            'businessHistory' => ($query['tab'] ?? '') === 'history'
                ? app(\App\Services\Umi\V2\UnifiedHistory::class)->member($userId, (int) ($query['history_entries_page'] ?? 1)) : null,
        ])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function quote()
    {
        return response()->json(app(\App\Services\Umi\V2\BestAskQuote::class)->status())->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request)
    {
        return $this->fundedSubmit($request);
    }

    public function preview(Request $request)
    {
        return $this->fundedPreview($request);
    }

    private function fundedSubmit(Request $request)
    {
        $input = $request->validate([
            'action' => 'required|in:enroll,activate,transfer,withdraw,spot_topup,stock_to_deepro',
            'request_key' => ['required', 'string', 'max:110', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'amount' => ['required_if:action,activate,transfer,withdraw,stock_to_deepro',
                'nullable', 'string', 'max:60',
                'regex:/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,'.($request->input('action')==='transfer'?'24':'18').'})?$/'],
            'sponsor_code' => 'nullable|string|max:40',
            'cycle_id' => 'required_if:action,transfer|nullable|integer|min:1',
            'pocket' => 'required_if:action,transfer|nullable|in:static,team,referral',
            'withdrawal_id' => 'required_if:action,spot_topup|nullable|integer|min:1',
        ]);
        $userId = (int) $request->user()->id;
        try {
            FundedRuntime::requireEnabled();
            $result = match ($input['action']) {
                'enroll' => app(FundedIntake::class)->enroll($userId,
                    $input['sponsor_code'] ?? null, $input['request_key']),
                'activate' => app(FundedIntake::class)->create($userId,
                    'spot', $input['amount'], $input['request_key']),
                'transfer' => app(FundedWithdrawal::class)->transfer($userId,
                    (int) $input['cycle_id'], $input['pocket'],
                    $input['amount'], $input['request_key']),
                'withdraw' => app(FundedWithdrawal::class)->request($userId,
                    $input['amount'], $input['request_key']),
                'spot_topup' => app(FundedWithdrawal::class)->topupFromSpot($userId,
                    (int) $input['withdrawal_id'], $input['request_key']),
                'stock_to_deepro' => StockShares::publicReceipt(app(StockShares::class)->toTradingWallet(
                    $userId, $input['amount'], $input['request_key'])),
            };
        } catch (DomainException | \InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422)
                ->header('Cache-Control', 'no-store');
        }
        return response()->json(['ok' => true, 'result' => $result,
            'state' => app(FundedDashboard::class)->member($userId)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function fundedPreview(Request $request)
    {
        $input = $request->validate([
            'action' => 'required|in:activate,withdraw',
            'amount' => ['required', 'string', 'max:60',
                'regex:/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,18})?$/'],
        ]);
        try {
            FundedRuntime::requireEnabled();
            $result = $input['action'] === 'activate'
                ? app(FundedIntake::class)->preview((int) $request->user()->id,
                    $input['amount'])
                : app(FundedWithdrawal::class)->preview((int) $request->user()->id,
                    $input['amount']);
            return response()->json($result)->header('Cache-Control', 'no-store');
        } catch (DomainException | \InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422)
                ->header('Cache-Control', 'no-store');
        }
    }
}
