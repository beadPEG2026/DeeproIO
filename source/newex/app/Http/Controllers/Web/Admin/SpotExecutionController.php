<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use App\Models\{Market\Market,User\User,Wallet\Wallet};
use App\Services\Market\{FundedLiquidity,PlatformCredit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
final class SpotExecutionController extends Controller
{
    public function index(FundedLiquidity $liquidity)
    {
        $markets=Market::with(['baseCurrency','quoteCurrency'])->orderBy('name')->get()->map(function($m)use($liquidity){
            $p=$liquidity->policy($m);$wallets=$p?Wallet::where('user_id',$p->maker_user_id)->whereIn('currency_id',[$m->base_currency_id,$m->quote_currency_id])->get()->keyBy('currency_id'):collect();
            $book=$liquidity->levels($m);
            return ['id'=>$m->id,'name'=>$m->name,'base'=>$m->baseCurrency->symbol,'quote'=>$m->quoteCurrency->symbol,'mode'=>$p->mode??'internal','configured_mode'=>!$p||($p->inherited??false)?'default':$p->mode,'maker_user_id'=>$p->maker_user_id??null,'max_quote_per_fill'=>$p->max_quote_per_fill??'1000','base_available'=>(string)($wallets->get($m->base_currency_id)->balance_in_trade??'0'),'quote_available'=>(string)($wallets->get($m->quote_currency_id)->balance_in_trade??'0'),'asks'=>count($book['asks']),'bids'=>count($book['bids'])];
        });
        return Inertia::render('Admin/Liquidity/Execution',['markets'=>$markets,'pool'=>app(PlatformCredit::class)->summary(),'audits'=>DB::table('market_execution_audits')->orderByDesc('id')->limit(30)->get()]);
    }
    public function pool(Request $request,PlatformCredit $credit)
    {
        $d=$request->validate(['enabled'=>'required|boolean','default_for_usdt'=>'required|boolean','credit_limit'=>['required','regex:/^\d{1,7}(?:\.\d{1,8})?$/D','numeric','gt:0','max:5000000'],'reason'=>'required|string|min:3|max:500']);
        DB::transaction(function()use($d,$request,$credit){
            $before=$credit->pool(true);
            if(bccomp($d['credit_limit'],$before->credit_limit,18)<0&&bccomp($d['credit_limit'],$credit->summary()['used'],18)<0)throw ValidationException::withMessages(['credit_limit'=>__('Limit cannot be lower than used credit.')]);
            $after=['enabled'=>$d['enabled'],'default_for_usdt'=>$d['default_for_usdt'],'credit_limit'=>$d['credit_limit'],'updated_at'=>now()];
            DB::table('platform_credit_pools')->where('id',PlatformCredit::POOL_ID)->update($after);
            DB::table('platform_credit_audits')->insert(['actor_id'=>$request->user()->id,'reason'=>$d['reason'],'before'=>json_encode($before),'after'=>json_encode($after),'created_at'=>now()]);
        });
        return back()->with('success',__('Settings saved'));
    }
    public function update(Request $request, Market $market)
    {
        $d=$request->validate(['mode'=>'required|in:default,internal,platform_maker,platform_credit','maker_user_id'=>'nullable|required_if:mode,platform_maker|integer|exists:users,id','max_quote_per_fill'=>['required','regex:/^\d{1,7}(?:\.\d{1,8})?$/D','numeric','gt:0','max:1000000']]);
        if($d['mode']==='platform_credit'&&$market->quoteCurrency->symbol!=='USDT')throw ValidationException::withMessages(['mode'=>__('Platform credit supports USDT markets only.')]);
        if($d['mode']!=='platform_maker')$d['maker_user_id']=null;
        DB::transaction(function()use($d,$request,$market){
            DB::statement('SELECT pg_advisory_xact_lock(8192026, ?)',[(int)$market->id]);
            if($d['mode']==='platform_maker'){
                $u=User::whereKey($d['maker_user_id'])->lockForUpdate()->firstOrFail();
                $wallets=Wallet::where('user_id',$u->id)->whereIn('currency_id',[$market->base_currency_id,$market->quote_currency_id])->orderBy('id')->lockForUpdate()->get();
                if($u->deactivated||$u->is_xn||$u->is_xm||$wallets->count()!==2||$wallets->contains(fn($w)=>bccomp((string)$w->balance_in_virtual_trade,'0',18)>0||bccomp((string)$w->balance_in_virtual_order,'0',18)>0))throw ValidationException::withMessages(['maker_user_id'=>__('Maker account must have both real trading wallets and no virtual funds.')]);
            }
            $before=DB::table('market_execution_policies')->where('market_id',$market->id)->lockForUpdate()->first();
            $after=$d+['updated_by'=>$request->user()->id,'updated_at'=>now()];
            if($d['mode']==='default')DB::table('market_execution_policies')->where('market_id',$market->id)->delete();
            else DB::table('market_execution_policies')->updateOrInsert(['market_id'=>$market->id],$after+['created_at'=>$before->created_at??now()]);
            DB::table('market_execution_audits')->insert(['market_id'=>$market->id,'actor_id'=>$request->user()->id,'before'=>$before?json_encode($before):null,'after'=>json_encode($after),'created_at'=>now()]);
        });
        return back()->with('success',__('Settings saved'));
    }
}
