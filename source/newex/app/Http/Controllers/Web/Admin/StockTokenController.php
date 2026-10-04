<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use App\Services\Market\StockCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
class StockTokenController extends Controller {
 public function index(StockCatalog $catalog){return Inertia::render('Admin/StockTokens/Index',['assets'=>$catalog->assets(true),'intervals'=>config('stock-tokens.intervals')]);}
 public function store(Request $request, \App\Services\Market\StockOnboarding $onboarding) {
  $d=$request->validate(['contract'=>['required','regex:/^0x[0-9a-fA-F]{40}$/'],'name'=>'nullable|string|max:80','asset_type'=>'required|in:stock,etf']);
  try { $result=$onboarding->apply($d['contract'],$d['name']??null,$d['asset_type']); }
  catch(\Throwable $e) {
   \Illuminate\Support\Facades\Log::warning('Stock onboarding rejected',['reason'=>$e->getMessage()]);
   $reason=match($e->getMessage()) {
    'alpha_identity_not_unique','unknown_stock'=>__('未找到唯一对应的 BSC 币股，请核对合约地址。'),
    'unsupported_stock_template'=>__('该资产不属于当前支持的 BSC Ondo 币股模板。'),
    'stock_market_data_incomplete'=>__('行情、K 线或双边盘口尚未齐全，请稍后重试。'),
    'stock_contract_conflict','existing_currency_identity_conflict','existing_market_identity_conflict'=>__('现有币种或交易对的合约配置不一致，请先核对。'),
    'existing_custom_market_requires_review'=>__('现有交易对使用自定义行情配置，需要先核对，系统未覆盖。'),
    default=>__('数据核验或保存未完成，请稍后重试；详细原因已记录到后台日志。'),
   };
   return back()->withErrors(['contract'=>__('配置未完成：').$reason]);
  }
  return back()->with('success',$result['symbol'].__(' 已完成模板配置；链上充提需独立验收。'));
 }
 public function update(Request $request,string $symbol){
  abort_unless(collect(\App\Services\Market\StockAssets::all())->contains('symbol',$symbol),404);
  $d=$request->validate(['display_enabled'=>'required|boolean','default_interval'=>['required',Rule::in(config('stock-tokens.intervals'))]]);
  \App\Models\Currency\Currency::where('symbol',$symbol)->firstOrFail()->update([
   'asset_display_enabled'=>$d['display_enabled'],'asset_chart_interval'=>$d['default_interval'],
  ]);
  return back()->with('success',__('币股展示设置已保存'));
 }
}
