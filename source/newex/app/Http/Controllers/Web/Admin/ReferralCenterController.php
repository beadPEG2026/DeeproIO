<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use App\Services\Referral\ExchangeRewards;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Cache};
use Inertia\Inertia;

final class ReferralCenterController extends Controller
{
    public function index(Request $r) {
        abort_unless($r->user()?->hasRole('superadmin'),403);
        $v=$r->validate(['user'=>'nullable|integer|min:1','page'=>'sometimes|integer|min:1|max:100000']);
        $user=$v['user']??null;
        $rows=DB::table('referral_transactions as r')->join('currencies as c','c.id','=','r.currency_id')
            ->leftJoin('exchange_referral_events as e','e.id','=','r.event_key')
            ->leftJoin('exchange_referral_receipts as p','p.referral_id','=','r.id')
            ->when($user,fn($q)=>$q->where(fn($q)=>$q->where('r.user_id',$user)->orWhere('e.source_user_id',$user)))
            ->orderByDesc('r.id')->select(['r.*','c.symbol','e.business','e.source_user_id','e.fee','e.rule_version','p.wallet_id','p.balance_field','p.balance_before','p.balance_after'])
            ->paginate(30)->withQueryString();
        $summary=DB::table('referral_transactions as r')->join('currencies as c','c.id','=','r.currency_id')
            ->leftJoin('exchange_referral_events as e','e.id','=','r.event_key')
            ->when($user,fn($q)=>$q->where(fn($q)=>$q->where('r.user_id',$user)->orWhere('e.source_user_id',$user)))
            ->selectRaw('c.symbol, r.balance_domain, r.credit_status, COUNT(*) AS count, SUM(r.amount)::text AS amount')
            ->groupBy('c.symbol','r.balance_domain','r.credit_status')->orderBy('c.symbol')->get();
        $exchange=DB::table('users as u')->leftJoin('users as p','p.id','=','u.referral_id')->where('u.deleted',false)
            ->when($user,fn($q)=>$q->where('u.id',$user))->orderByDesc('u.id')->limit(100)
            ->get(['u.id','u.email','u.referral_code','u.referral_id','u.vip','u.is_xn','p.referral_code as parent_code']);
        $umi=DB::table('umi_business_accounts as a')->leftJoin('umi_business_accounts as p','p.id','=','a.parent_id')->leftJoin('users as u','u.id','=','a.user_id')
            ->when($user,fn($q)=>$q->where('a.user_id',$user))->orderByDesc('a.id')->limit(100)
            ->select(['a.id','a.user_id','a.code','a.parent_id','a.legacy_id','a.legacy_parent_id','a.fixture','p.code as parent_code'])
            ->selectRaw('(u.id IS NOT NULL AND NOT u.deleted AND NOT u.deactivated AND u.email_verified_at IS NOT NULL AND NOT a.fixture) AS can_invite')->get();
        return Inertia::render('Admin/ReferralCenter/Index',[
            'rules'=>['version'=>ExchangeRewards::VERSION,'rates'=>ExchangeRewards::RATES], 'rows'=>$rows, 'summary'=>$summary,
            'exchange'=>$exchange,'umi'=>$umi,'filters'=>['user'=>$user], 'heartbeat'=>Cache::get('deepro.exchange_referral.last_run'),
        ])->toResponse($r)->header('Cache-Control','private, no-store');
    }
}
