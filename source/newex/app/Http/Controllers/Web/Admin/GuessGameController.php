<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
class GuessGameController extends Controller
{
    public function index() { return view('admin.review-table',['title'=>'猜涨跌历史产品','notice'=>'此产品已隐藏，仅保留历史记录查询。','columns'=>['id'=>'ID','name'=>'名称','status'=>'状态','min_amount'=>'最低额','max_amount'=>'最高额','created_at'=>'时间'],'rows'=>DB::table('guess_games')->select('id','name','status','min_amount','max_amount','created_at')->orderByDesc('id')->paginate(30)]); }
    public function create() { abort(410,__('This product is not currently available.')); }
    public function store() { abort(410,__('This product is not currently available.')); }
}
