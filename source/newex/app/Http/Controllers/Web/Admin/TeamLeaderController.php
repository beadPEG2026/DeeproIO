<?php
namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User\User;
use App\Repositories\Report\ReportRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class TeamLeaderController extends Controller
{
    private function scope(): array
    {
        // Share the existing report visibility rules; never accept a caller-supplied user scope.
        return (new class extends ReportRepository {
            public function visibleIds(): array { return $this->getDashboardScopeUserIds(); }
        })->visibleIds();
    }
    private function listing(string $title, string $table, array $columns, ?int $userId = null)
    {
        $ids = $this->scope();
        if ($userId !== null) abort_unless(in_array($userId, $ids, true),403);
        $query = DB::table($table)->whereIn($table === 'users' ? 'id' : 'user_id', $userId === null ? $ids : [$userId]);
        $filters = request()->validate(['user_id'=>['nullable','integer','min:1']]);
        if (!empty($filters['user_id'])) $query->where($table === 'users' ? 'id' : 'user_id', $filters['user_id']);
        $rows = $query->select(array_keys($columns))->orderByDesc('created_at')->paginate(30)->withQueryString();
        return view('admin.review-table', compact('title','columns','rows') + ['notice'=>'沿用报表的团队可见范围，当前列表统计真实账户；虚拟账户不计入。']);
    }
    public function dashboard() { return $this->users(); }
    public function users() { return $this->listing('团队账户','users',['id'=>'UID','email'=>'邮箱','referral_id'=>'上级 UID','active'=>'启用','created_at'=>'注册时间']); }
    public function userDetail(User $user) { return $this->listing('团队账户详情','users',['id'=>'UID','email'=>'邮箱','referral_id'=>'上级 UID','active'=>'启用','created_at'=>'注册时间'], $user->id); }
    public function orders() { return $this->listing('团队现货委托','orders',['id'=>'订单','user_id'=>'UID','market_id'=>'市场','type'=>'类型','side'=>'方向','price'=>'价格','quantity'=>'剩余数量','created_at'=>'时间']); }
    public function positions() { return $this->listing('团队合约记录','futures_contract',['id'=>'合约','user_id'=>'UID','market_id'=>'市场','created_at'=>'创建时间']); }
    public function trades() { return $this->listing('团队成交','transactions',['id'=>'成交','user_id'=>'UID','market_id'=>'市场','order_side'=>'方向','price'=>'价格','base_currency'=>'数量','quote_currency'=>'金额','fee'=>'手续费','created_at'=>'时间']); }
    public function deposits() { return $this->listing('团队充值','deposits',['id'=>'记录','user_id'=>'UID','currency_id'=>'币种','amount'=>'数量','status'=>'状态','created_at'=>'时间']); }
    public function withdrawals() { return $this->listing('团队提现','withdrawals',['id'=>'记录','user_id'=>'UID','currency_id'=>'币种','amount'=>'数量','fee'=>'手续费','status'=>'状态','created_at'=>'时间']); }
    public function commissions() { return $this->listing('团队佣金','referral_transactions',['id'=>'记录','user_id'=>'UID','currency_id'=>'币种','amount'=>'数量','is_credited'=>'已入账','created_at'=>'时间']); }
    public function reports() { return $this->trades(); }
}
