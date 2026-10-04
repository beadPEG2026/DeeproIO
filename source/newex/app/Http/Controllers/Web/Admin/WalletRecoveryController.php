<?php
namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Custody\CustodyAccess;
use App\Services\Deposit\DepositRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletRecoveryController extends Controller
{
    private const EVIDENCE_FIELDS = ['chain','txn','event_index','contract','sender','destination','address','amount','decimals','block_number','block_hash','confirmations','observed_at','finality','deposit_mode','block','original_block','original_hash','observed_hash','checked_at','raw_amount','vout'];
    private const EVIDENCE_WRAPPERS = ['first_observed','latest_observed','conflict','restored'];
    private const MAX_EVIDENCE_DEPTH = 4;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(CustodyAccess::allowed($request->user()), 403);
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['status' => 'sometimes|in:open,resolved,all', 'chain' => 'nullable|string|max:20']);
        $status = $filters['status'] ?? 'open';
        $chain = $filters['chain'] ?? '';
        $events = DB::table('deposit_review_events')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($chain !== '', fn ($q) => $q->where('chain', $chain))
            ->orderBy('created_at')->paginate(25, ['id','status','reason','chain','txn','event_index','deposit_id','channel_id','evidence','attempts','created_at','resolved_at'], 'events_page')->withQueryString();
        $events->getCollection()->transform(function ($event) {
            $proof = json_decode($event->evidence, true);
            $event->evidence = $this->publicEvidence(is_array($proof) ? $proof : []);
            return $event;
        });
        $sweeps = DB::table('deposits')->whereNotNull('sweep_error')->orderBy('sweep_attempted_at')
            ->paginate(25, ['id','currency_id','status','wallet_transfer_status','sweep_error','sweep_attempted_at','sweep_retry_at'], 'sweeps_page')->withQueryString();
        $transfers = DB::table('custody_transfers')->where(function ($q) {
            $q->where('status', 'review')->orWhere(function ($q) {
                $q->whereIn('status', ['pending','approved','prepared','confirming'])->where('created_at', '<=', now()->subMinutes(30));
            });
        })->orderBy('created_at')->paginate(25, ['id','chain','purpose','status','txn','amount','last_error','created_at','last_attempt_at'], 'transfers_page')->withQueryString();
        return view('admin.wallet-recovery', [
            'events'=>$events, 'sweeps'=>$sweeps, 'transfers'=>$transfers, 'status'=>$status, 'chain'=>$chain,
            'chains'=>DB::table('deposit_review_events')->distinct()->orderBy('chain')->pluck('chain'),
            'verified'=>CustodyAccess::fresh($request), 'checkedAt'=>now()->toIso8601String(),
        ]);
    }

    /** Only known observation wrappers may contain nested public proof fields. */
    private function publicEvidence(array $proof, int $depth = 0): array
    {
        $visible = [];
        foreach ($proof as $field => $value) {
            if (in_array($field, self::EVIDENCE_FIELDS, true) && (is_scalar($value) || $value === null)) {
                $visible[$field] = $value;
            } elseif ($depth < self::MAX_EVIDENCE_DEPTH && in_array($field, self::EVIDENCE_WRAPPERS, true) && is_array($value)) {
                $nested = $this->publicEvidence($value, $depth + 1);
                if ($nested !== []) $visible[$field] = $nested;
            }
        }
        return $visible;
    }

    public function retry(Request $request, int $id)
    {
        CustodyAccess::requireFresh($request);
        abort_unless(DB::table('deposit_review_events')->where('id', $id)->exists(), 404);
        try {
            $result = app(DepositRecovery::class)->retry($id);
            if ($request->expectsJson()) return response()->json($result);
            return back()->with('recovery_status', __('已完成重新核验，请查看当前状态；只有链上凭证与账户检查均通过才会处理入账。'));
        } catch (\RuntimeException $e) {
            $code = preg_match('/^(DEPOSIT|CUSTODY)_[A-Z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'DEPOSIT_RECHECK_FAILED';
            if ($request->expectsJson()) return response()->json(['message'=>__($code)], 422);
            return back()->withErrors(['retry'=>__($code)]);
        }
    }
}
