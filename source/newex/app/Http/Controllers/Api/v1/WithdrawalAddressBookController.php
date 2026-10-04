<?php
namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Wallet\Rules\WithdrawAddressValidationRule;
use App\Models\Currency\Currency;
use App\Services\Wallet\WithdrawalNetworkPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WithdrawalAddressBookController extends Controller
{
    public function index(Request $request) {
        $v=$request->validate(['symbol'=>'required|string|max:32','network'=>'required|integer']);
        $currency=Currency::where('symbol',$v['symbol'])->firstOrFail();
        return response()->json(DB::table('withdrawal_address_book')->where('user_id',$request->user()->id)
            ->where('currency_id',$currency->id)->where('network_id',$v['network'])
            ->orderBy('label')->get(['id','label','address','payment_id']))->header('Cache-Control','private, no-store');
    }

    public function store(Request $request) {
        $v=$request->validate(['symbol'=>'required|string|max:32','network'=>'required|integer',
            'label'=>'required|string|max:60','address'=>['bail','required','string','max:255',new WithdrawAddressValidationRule()],
            'payment_id'=>'nullable|integer|min:0|max:4294967295']);
        $currency=Currency::where('symbol',$v['symbol'])->firstOrFail();
        app(WithdrawalNetworkPolicy::class)->assertSupported($currency->id,(int)$v['network']);
        if($currency->has_payment_id && (!isset($v['payment_id']) || $v['payment_id']===''))
            throw ValidationException::withMessages(['payment_id'=>__('Memo is required for this asset.')]);
        $result=DB::transaction(function() use($request,$currency,$v) {
            DB::table('users')->where('id',$request->user()->id)->lockForUpdate()->first();
            $query=DB::table('withdrawal_address_book')->where('user_id',$request->user()->id);
            $fingerprint=hash('sha256',implode('|',[$currency->id,$v['network'],$v['address'],$v['payment_id']??'']));
            $existing=(clone $query)->where('fingerprint',$fingerprint)->first();
            if(!$existing && $query->count()>=50) throw ValidationException::withMessages(['label'=>__('Address book limit reached. Remove an unused address first.')]);
            if($existing) { $query->where('id',$existing->id)->update(['label'=>$v['label'],'updated_at'=>now()]);return $existing->id; }
            return DB::table('withdrawal_address_book')->insertGetId(['user_id'=>$request->user()->id,'currency_id'=>$currency->id,
                'network_id'=>$v['network'],'label'=>$v['label'],'address'=>$v['address'],'payment_id'=>$v['payment_id']??null,
                'fingerprint'=>$fingerprint,'created_at'=>now(),'updated_at'=>now()]);
        });
        return response()->json(['id'=>$result],201)->header('Cache-Control','no-store');
    }

    public function destroy(Request $request, int $address) {
        $deleted=DB::table('withdrawal_address_book')->where('user_id',$request->user()->id)->where('id',$address)->delete();
        abort_unless($deleted,404);
        return response()->noContent();
    }
}
