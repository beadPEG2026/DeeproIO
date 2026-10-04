<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Umi\V2\FundedBurn;
use App\Services\Umi\V2\FundedConfiguration;
use App\Services\Umi\V2\FundedDashboard;
use App\Services\Umi\V2\FundedSettlement;
use App\Services\Umi\V2\MemberEnrollment;
use App\Services\Umi\V2\StockShares;
use App\Services\Umi\V2\FundedRuntime;
use DomainException;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class UmiV2FundedAdminController extends Controller
{
    public function index(Request $request, FundedDashboard $dashboard)
    {
        \App\Support\UmiAdminAccess::authorize($request->user(), 'read');
        if (!FundedRuntime::schemaReady()) { return app(UmiAdminController::class)->index($request); }
        $v = $request->validate(['tab' => 'nullable|in:operations,burn,members,stock,orders,settings,archive,history,cutover,records',
            'batch_id'=>'nullable|integer|min:1', 'batch_page'=>'nullable|integer|min:1|max:100000',
            'legacy_account' => 'nullable|integer|min:1',
            'legacy_section' => 'nullable|in:overview,burn,team-income,usdt,team-usdt,team,treasury,bao,vip',
            'legacy_page' => 'nullable|integer|min:1|max:100000',
            'member_search' => 'nullable|string|max:60', 'history_search' => 'nullable|string|max:60',
            'page' => 'nullable|integer|min:1|max:100000', 'accounts_page' => 'nullable|integer|min:1|max:100000',
            'account' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100',
            'history_entries_page' => 'nullable|integer|min:1|max:100000',
            'history_page' => 'nullable|integer|min:1|max:100000', 'history_account' => 'nullable|integer|min:1']);
        if (in_array($v['tab'] ?? '', ['archive','cutover'], true) || isset($v['legacy_account'])) { \App\Support\UmiAdminAccess::authorize($request->user(), 'legacy'); }
        if (($v['tab'] ?? '') === 'settings') { abort_unless(\App\Support\UmiAdminAccess::allows($request->user(), 'settings') || \App\Support\UmiAdminAccess::allows($request->user(), 'quotes'), 403); }
        return Inertia::render('Umi/FundedConsole', ['capabilities' => \App\Support\UmiAdminAccess::capabilities($request->user()), 'state' => $dashboard->admin($v['member_search'] ?? ''),
            'initialTab' => $v['tab'] ?? (!empty($v['member_search']) ? 'members' : 'operations'),
            'accountHistory' => isset($v['legacy_account']) ? app(\App\Services\Umi\LegacyPortfolio::class)->show(\App\Models\Umi\LegacyAccount::findOrFail($v['legacy_account']),$v['legacy_section']??'overview',(int)($v['legacy_page']??1)) : null,
            'accountSection' => $v['legacy_section']??'overview',
            'cutover' => ($v['tab']??'')==='cutover' ? \App\Services\Umi\V2\FundedTime::forDisplay(app(\App\Services\Umi\V2\AccountCutover::class)->preview(isset($v['batch_id'])?(int)$v['batch_id']:null)) : null,
            'captureBatches'=>($v['tab']??'')==='cutover' ? app(\App\Services\Umi\V2\CaptureSnapshots::class)->batches((int)($v['batch_page']??1)) : null,
            'archive' => ($v['tab'] ?? '') === 'archive' ? app(UmiAdminController::class)->archiveData($request) : null,
            'history' => ($v['tab'] ?? '') === 'history'
                ? app(\App\Services\Umi\V2\UnifiedHistory::class)->admin($v['history_search'] ?? '',
                    (int) ($v['history_page'] ?? 1), isset($v['history_account']) ? (int) $v['history_account'] : null, (int) ($v['history_entries_page'] ?? 1)) : null,
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request)
    {
        \App\Support\UmiAdminAccess::authorizeAction($request->user(), (string)$request->input('action'));
        $input = $request->validate([
            'action' => 'required|in:settings,quote,spot_quote,day,queue_burn,sync_burn,activate_legacy,stock_settle,stock_transfer,stock_writeoff,account_cutover,approve_snapshot,recover_points,cancel_intake',
            'request_key' => ['required', 'string', 'max:110', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'revision'=>'required_if:action,settings|nullable|string|size:64',
            'pool_user_id' => 'nullable|integer|min:1',
            'dedicated_address' => 'nullable|string|max:42',
            'intake_enabled' => 'required_if:action,settings|nullable|boolean',
            'settlement_enabled' => 'required_if:action,settings|nullable|boolean',
            'withdrawal_enabled' => 'required_if:action,settings|nullable|boolean',
            'settlement_rate' => 'nullable|string|max:30|regex:/^0\.[0-9]{1,6}$/',
            'batch_id'=>'required_if:action,account_cutover,approve_snapshot|nullable|integer|min:1',
            'cutoff_at'=>'required_if:action,approve_snapshot|nullable|date',
            'account_cutover_enabled' => 'nullable|boolean',
            'source_sha256' => 'required_if:action,account_cutover,approve_snapshot|nullable|string|size:64|regex:/^[a-f0-9]{64}$/',
            'quote_id' => 'required_if:action,account_cutover|nullable|integer|min:1',
            'starts_on' => 'required_if:action,account_cutover|nullable|date_format:Y-m-d',
            'stock_transfer_enabled' => 'required_if:action,settings|nullable|boolean',
            'asset' => 'required_if:action,quote|nullable|in:HK08379_USDT',
            'price' => ['required_if:action,quote', 'nullable', 'string', 'max:60',
                'regex:/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,24})?$/'],
            'source' => 'required_if:action,quote|nullable|string|max:120',
            'source_ref' => 'required_if:action,quote|nullable|string|max:160',
            'observed_at' => 'required_if:action,quote|nullable|date',
            'business_date' => 'required_if:action,day|nullable|date_format:Y-m-d',
            'static_rate' => ['required_if:action,day', 'nullable', 'string', 'max:60',
                'regex:/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,24})?$/'],
            'intent_id'=>'required_if:action,cancel_intake|nullable|integer|min:1',
            'task_id'=>'required_if:action,recover_points|nullable|integer|min:1',
            'lot_id' => 'required_if:action,queue_burn,sync_burn|nullable|integer|min:1',
            'user_id' => 'required_if:action,activate_legacy|nullable|integer|min:1',
            'from_member_id' => 'required_if:action,stock_transfer,stock_writeoff|nullable|integer|min:1',
            'to_member_id' => 'required_if:action,stock_transfer|nullable|integer|min:1',
            'shares' => ['required_if:action,stock_transfer,stock_writeoff', 'nullable', 'string', 'max:60',
                'regex:/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,24})?$/'],
            'reason' => 'required_if:action,stock_transfer,stock_writeoff,approve_snapshot,cancel_intake|nullable|string|max:240',
        ]);
        $actor = (int) $request->user()->id;
        try {
            if ($input['action'] !== 'settings') { FundedRuntime::requireEnabled(); }
            elseif (!FundedRuntime::schemaReady()) { throw new DomainException('UMI 配置服务正在准备中。'); }
            $result = match ($input['action']) {
                'settings' => app(FundedConfiguration::class)->save($input,
                    $input['request_key'], $actor),
                'quote' => app(FundedConfiguration::class)->approveQuote(
                    $input['asset'], $input['price'], $input['source'],
                    $input['source_ref'], $input['observed_at'], $actor),
                'day' => app(FundedSettlement::class)->runDay(
                    $input['business_date'], $input['static_rate'], $actor),
                'queue_burn' => app(FundedBurn::class)->queue((int) $input['lot_id']),
                'sync_burn' => app(FundedBurn::class)->synchronize((int) $input['lot_id']),
                'cancel_intake' => app(\App\Services\Umi\V2\FundedCancellation::class)->cancelIntake((int)$input['intent_id'],$actor,$input['reason']),
                'recover_points' => app(\App\Services\Umi\V2\FundedRecovery::class)->retry((int)$input['task_id'],$actor),
                'spot_quote' => app(FundedConfiguration::class)->quote('UMI_USDT'),
                'activate_legacy' => app(MemberEnrollment::class)->activateHistorical(
                    (int) $input['user_id'], $input['request_key'], $actor),
                'account_cutover' => app(\App\Services\Umi\V2\AccountCutover::class)->run($input['source_sha256'],(int)$input['quote_id'],$input['starts_on'],$actor,(int)$input['batch_id']),
                'approve_snapshot'=>app(\App\Services\Umi\V2\CaptureSnapshots::class)->approve((int)$input['batch_id'],$input['source_sha256'],$input['cutoff_at'],$input['reason'],$actor),
                'stock_settle' => app(StockShares::class)->settleDue(),
                'stock_transfer' => app(StockShares::class)->transfer(
                    (int) $input['from_member_id'], (int) $input['to_member_id'],
                    $input['shares'], $input['request_key'], $actor, $input['reason']),
                'stock_writeoff' => app(StockShares::class)->writeOff(
                    (int) $input['from_member_id'], $input['shares'],
                    $input['request_key'], $actor, $input['reason']),
            };
        } catch (DomainException | \InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422)
                ->header('Cache-Control', 'no-store');
        }
        return response()->json(['ok' => true, 'result' => $result,
            'state' => app(FundedDashboard::class)->admin()])
            ->header('Cache-Control', 'private, no-store');
    }
}
