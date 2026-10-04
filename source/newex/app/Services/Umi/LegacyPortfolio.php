<?php
namespace App\Services\Umi;
use App\Models\Umi\LegacyAccount;
use Illuminate\Support\Facades\{DB,Crypt};
class LegacyPortfolio {
    public function show(LegacyAccount $a,string $section='overview',int $page=1): array {
        $p=$a->profile; $i=$a->identity;
        $csv=DB::table('umi_legacy_summaries')->where('legacy_id',$a->legacy_id)->value('payload');
        $summary=$csv?json_decode(Crypt::decryptString($csv),true):[];
        unset($summary['交易所UID'],$summary['用户ID']);
        $sources=['burn'=>'burn__records','team-income'=>'team-umi-flow','usdt'=>'usdt__transactions','team-usdt'=>'team-usdt-flow'];
        $history=null;
        if(isset($sources[$section])) {
            $pager=DB::table('umi_legacy_records')->where('legacy_id',$a->legacy_id)->where('source',$sources[$section])->orderByDesc('id')->paginate(20,['*'],'page',$page);
            $history=['rows'=>$pager->getCollection()->map(function($r){$v=json_decode(Crypt::decryptString($r->payload),true);return array_diff_key($v,array_flip(['operator','user_nickname','user_uuid','remark']));})->values()->all(),
                'page'=>$pager->currentPage(),'last_page'=>$pager->lastPage(),'total'=>$pager->total()];
        }
        $children=[];
        if($section==='team') {
            $pager=LegacyAccount::where('parent_legacy_id',$a->legacy_id)->orderBy('legacy_id')->paginate(20,['*'],'page',$page);
            $children=['rows'=>$pager->getCollection()->map(fn($child)=>['id'=>$child->legacy_id,'uuid'=>$child->legacy_uuid,'level'=>$child->level,'nickname'=>$child->identity['nickname']??''])->all(),
                'page'=>$pager->currentPage(),'last_page'=>$pager->lastPage(),'total'=>$pager->total()];
        }
        return ['id'=>$a->legacy_id,'uuid'=>$a->legacy_uuid,'nickname'=>$i['nickname']??'',
            'parent_id'=>$a->parent_legacy_id,'parent_in_scope'=>$a->parent_legacy_id?LegacyAccount::whereKey($a->parent_legacy_id)->exists():null,
            'level'=>$a->level,'legacy_status'=>$a->legacy_status,'activation_status'=>$a->activation_status,
            'quota'=>$p['quota_summary']??[],'balances'=>$p['balances']??[],'dapp_balance'=>$p['dapp_balance_umi']??null,
            'reserve'=>$p['reserve_balance']??[],'cumulative'=>$p['cumulative_earnings']??[],
            'performance'=>array_diff_key($p['performance']??[],array_flip(['nickname','uuid','user_id'])),
            'history'=>$history,'children'=>$children,'team'=>$section==='team'?app(LegacyRelations::class)->forAccount($a,$page):null,'summary'=>$summary,'imported_at'=>DB::table('umi_import_batches')->where('id',$a->batch_id)->value('created_at'),
            'asset'=>config('umi.asset'),'operations_enabled'=>false];
    }
    public function feePreview(string $type,string $amount): array {
        $rate=config('umi.observed_transfer_rates')[$type]??null;
        if($rate===null || !preg_match('/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,18})?$/',$amount) || bccomp($amount,'0',18)<=0) throw new \InvalidArgumentException(__('金额或收益类别无效。'));
        $fee=bcmul($amount,$rate,18); $net=bcsub($amount,$fee,18);
        return ['amount'=>$amount,'rate'=>$rate,'fee'=>$fee,'net'=>$net,'legacy_display_net'=>bcadd($net,'0',2),'executable'=>false];
    }
}
