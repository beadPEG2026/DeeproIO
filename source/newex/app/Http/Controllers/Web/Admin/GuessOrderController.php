<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
class GuessOrderController extends Controller
{
    public function index(?int $id = null) {
        $data=request()->validate(['user_id'=>['nullable','integer','min:1'],'status'=>['nullable','in:pending,won,lost,win,lose,cancelled,settled']]);
        $q=DB::table('guess_orders')->select('id','user_id','game_id','amount','currency_symbol','status','created_at');
        if ($id !== null) { abort_unless((clone $q)->where('id',$id)->exists(),404); $q->where('id',$id); }
        foreach($data as $key=>$value) if($value!==null) $q->where($key,$value);
        return view('admin.review-table',['title'=>'猜涨跌历史订单','notice'=>'已隐藏产品的历史记录查询。','columns'=>['id'=>'ID','user_id'=>'UID','game_id'=>'产品','amount'=>'数量','currency_symbol'=>'币种','status'=>'状态','created_at'=>'时间'],'rows'=>$q->orderByDesc('id')->paginate(30)->withQueryString()]);
    }
    public function show(int $id) { return $this->index($id); }
}
