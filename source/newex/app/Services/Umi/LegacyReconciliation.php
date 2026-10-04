<?php
namespace App\Services\Umi;

use App\Models\Umi\LegacyAccount;
use Illuminate\Support\Facades\{DB,Crypt};

/** Re-evaluate archived accounting identities; never credit or repair balances. */
final class LegacyReconciliation
{
    private const SCALE=36;
    private array $checks=[];

    public function report(?int $legacyId=null): array
    {
        if(DB::transactionLevel()===0)return DB::transaction(function()use($legacyId){
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            return $this->build($legacyId);
        });
        return $this->build($legacyId);
    }

    private function build(?int $legacyId): array
    {
        $this->checks=[];$accounts=LegacyAccount::orderBy('legacy_id')->get()->keyBy('legacy_id');
        if($legacyId!==null&&!$accounts->has($legacyId))abort(404);
        $selected=$legacyId===null?$accounts:$accounts->only([$legacyId]);
        $summaries=DB::table('umi_legacy_summaries')->orderBy('legacy_id')->get()->keyBy('legacy_id');
        $records=DB::table('umi_legacy_records')->orderBy('source')->orderBy('source_id')->get();
        $fingerprint=hash('sha256',json_encode([
            $accounts->map(fn($a)=>[$a->source_hash,$a->parent_legacy_id,$a->level,$a->legacy_status])->all(),
            $summaries->map(fn($s)=>[$s->legacy_id,$s->source_hash])->all(),
            $records->map(fn($r)=>[$r->source,$r->source_id,$r->source_hash])->all(),
        ]));
        foreach($selected as $a){
            $id=(int)$a->legacy_id;
            try{$errors=app(LegacyIntegrity::class)->account($a);}catch(\Throwable $e){$errors=['unreadable'];}
            $this->add($id,'account.integrity','原账户及关系与封存资料一致',$errors?'invalid':'matched',implode(', ',$errors),'无差异','原用户列表及 profile');
            if($errors)continue;
            $profile=$a->profile;$seen=[$id=>true];$parent=$a->parent_legacy_id;$cycle=false;$outside=null;
            while($parent){
                if(isset($seen[$parent])){$cycle=true;break;}$seen[$parent]=true;
                if(!$accounts->has($parent)){$outside=(int)$parent;break;}$parent=$accounts[$parent]->parent_legacy_id;
            }
            $directOutside=$a->parent_legacy_id&&!$accounts->has($a->parent_legacy_id);
            $this->add($id,'team.path','原直接上级引用及关系循环检查',$cycle?'invalid':($directOutside?'missing':'matched'),$outside?(string)$outside:null,null,'原 inviter.user_id',$cycle?'检测到关系循环，须核对原始资料。':($outside?'祖先路径止于范围外上级；保留原引用，不重新挂靠。直接上级在范围内不代表祖先资料完整。':null));
            $p=$profile['quota_summary']??[];
            $this->equation($id,'quota.nonnegative','剩余额度不能为负',$p['total_quota']??null,[$p['used_quota']??null],'gte','profile.quota_summary');
            $s=$summaries->get($id);
            if(!$s){$this->add($id,'summary.missing','完整资产与收益拆分','missing',null,null,'伞下 CSV','缺少该用户的 CSV 汇总，不能用零代替。');continue;}
            try{$raw=Crypt::decryptString($s->payload);$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$valid=hash_equals($s->source_hash,hash('sha256',$raw));}catch(\Throwable $e){$valid=false;}
            if(!$valid){$this->add($id,'summary.integrity','CSV 汇总内容校验','invalid',null,null,'封存 CSV');continue;}
            $this->equation($id,'quota.csv_total','原总额度与 CSV 总额度',$p['total_quota']??null,[$data['总额度(UMI)']??null],'sum','profile 与伞下 CSV');
            $this->equation($id,'quota.csv_used','原已用额度与 CSV 已用额度',$p['used_quota']??null,[$data['已用额度(UMI)']??null],'sum','profile 与伞下 CSV');
            $this->equation($id,'asset.csv_total','原可提资产与 CSV 合计',$profile['dapp_balance_umi']??null,[$data['DApp可提资产(UMI)']??null],'sum','profile 与伞下 CSV');
            $this->equation($id,'quota.used_profit','已用额度与累计总收益',$data['已用额度(UMI)']??null,[$data['总利润(UMI)']??null],'sum','伞下 CSV');
            $this->equation($id,'profit.components','累计总收益的四项构成',$data['总利润(UMI)']??null,array_map(fn($k)=>$data[$k]??null,['直推利润(UMI)','团队利润(UMI)','理财利润(UMI)','UMI宝利润(UMI)']),'sum','伞下 CSV');
            $this->equation($id,'asset.components','可提资产的五项构成',$data['DApp可提资产(UMI)']??null,array_map(fn($k)=>$data[$k]??null,['[核查]主余额(UMI)','[核查]理财收益余额(UMI)','[核查]团队收益余额(UMI)','[核查]直推收益余额(UMI)','[核查]复投宝本金(UMI)']),'sum','伞下 CSV','组合总额不再另记一笔余额；备用金不在此公式中。');
            $this->equation($id,'quota.sources','总额度与购买、赠送来源之和',$data['总额度(UMI)']??null,[$data['用户购买额度(UMI)']??null,$data['管理员赠送额度(UMI)']??null],'sum','伞下 CSV','差额需要原调整或作废凭证解释，不自动补差或减额度。');
        }
        $flows=['team-usdt-flow'=>[],'usdt__transactions'=>[]];$sourceCounts=[];$deleted=0;
        foreach($records as $record){
            $id=(int)$record->legacy_id;if($legacyId!==null&&$legacyId!==$id)continue;
            $sourceCounts[$record->source]=($sourceCounts[$record->source]??0)+1;
            try{$raw=Crypt::decryptString($record->payload);$v=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$valid=hash_equals($record->source_hash,hash('sha256',$raw));}catch(\Throwable $e){$valid=false;}
            $source=$record->source.' #'.$record->source_id;
            if(!$valid||!$accounts->has($id)||!isset($v['user_id'],$v['id'])||(int)$v['user_id']!==$id||(string)$v['id']!==$record->source_id){$this->add($id,'record.integrity','原记录及所属用户一致','invalid',null,null,$source);continue;}
            if($record->source==='burn__records'){
                $this->equation($id,'burn.quota','燃烧数量 × 原倍数 = 原额度',$v['quota_granted']??null,[$v['burn_amount']??null,$v['multiplier']??null],'multiply',$source);
                $this->equation($id,'burn.valuation','燃烧数量 × 原价格 = 原美元价值',$v['burn_value_usdt']??null,[$v['burn_amount']??null,$v['token_price']??null],'multiply',$source,null,'0.00000001','USDT');
                if((int)($v['status']??-1)===3){$deleted++;$this->add($id,'burn.deleted','已删除的历史燃烧记录','notice',(string)$v['status'],null,$source,'保留原记录及校验结果，不重放、不恢复权益。');}
                $value=$this->decimal($v['burn_value_usdt']??null);$multiple=$this->decimal($v['multiplier']??null);
                if($value!==null&&$multiple!==null){
                    $exception=(bccomp($multiple,'4',self::SCALE)===0&&(bccomp($value,'2000',self::SCALE)<0||bccomp($value,'5000',self::SCALE)>=0))||(bccomp($multiple,'5',self::SCALE)===0&&bccomp($value,'5000',self::SCALE)<0);
                    if($exception)$this->add($id,'burn.tier_exception','历史倍数与材料展示档位不同','difference',$this->display($multiple),null,$source,'保留原价、原倍数和原额度；不能套用当前档位重算。');
                }
            }
            if(isset($flows[$record->source]))$flows[$record->source][]=$v;
        }
        $index=[];$viewCounts=[];
        foreach($flows['usdt__transactions'] as $v)if(($key=$this->flowKey($v))!==null)$index[$key][]=$v;
        foreach($flows['team-usdt-flow'] as $v)if(($key=$this->flowKey($v))!==null)$viewCounts[$key]=($viewCounts[$key]??0)+1;
        foreach($flows['team-usdt-flow'] as $v){
            $id=(int)$v['user_id'];$key=$this->flowKey($v);$matches=$key!==null?($index[$key]??[]):[];
            $state=$key===null?'invalid':(count($matches)===1&&$viewCounts[$key]===1?'matched':(count($matches)===0?'missing':'ambiguous'));
            $this->add($id,'flow.usdt_overlap','USDT 充提视图与总流水对应',$state,(string)$v['id'],$state==='matched'?(string)$matches[0]['id']:null,'team-usdt-flow / usdt__transactions','按原用户、绝对金额、变动前后余额匹配；仅建立对应关系，不重复入账。');
            if($state==='matched'&&($v['created_at']??null)!==($matches[0]['created_at']??null))$this->add($id,'flow.time_difference','同一 USDT 记录在两视图中的时间不同','notice',$v['created_at']??null,$matches[0]['created_at']??null,'team-usdt-flow #'.$v['id'],'保留两个原时间，不擅自统一。');
        }
        $counts=array_fill_keys(['matched','difference','missing','invalid','ambiguous','notice'],0);$byRule=[];
        foreach($this->checks as $check){$counts[$check['state']]++;$byRule[$check['code']][$check['state']]=($byRule[$check['code']][$check['state']]??0)+1;}
        return ['generated_at'=>now()->toIso8601String(),'snapshot_fingerprint'=>$fingerprint,'scope'=>'已取得的原 UMI 后台可见范围，非全平台数据库',
            'accounts'=>$selected->count(),'summary_accounts'=>$summaries->filter(fn($s)=>$selected->has($s->legacy_id))->count(),'record_sources'=>$sourceCounts,'deleted_burn_records'=>$deleted,
            'counts'=>$counts,'by_rule'=>$byRule,'checks'=>$this->checks,'file_contents_checked'=>false,'read_only'=>true,'funds_credited'=>false,'settlement_enabled'=>false];
    }

    private function decimal(mixed $value):?string {
        if(is_int($value))$value=(string)$value;
        return is_string($value)&&preg_match('/^-?(?:0|[1-9][0-9]{0,77})(?:\.[0-9]{1,36})?$/D',$value)?$value:null;
    }
    private function display(string $value):string {
        if(str_contains($value,'.'))$value=rtrim(rtrim($value,'0'),'.');return $value==='-0'?'0':$value;
    }
    private function equation(int $id,string $code,string $label,mixed $actual,array $parts,string $operation,string $source,?string $note=null,string $tolerance='0',string $unit='UMI'):void {
        if($actual===null||in_array(null,$parts,true)){$this->add($id,$code,$label,'missing',null,null,$source,'字段缺失，未补零。',$unit);return;}
        $left=$this->decimal($actual);$values=array_map(fn($v)=>$this->decimal($v),$parts);
        if($left===null||in_array(null,$values,true)){$this->add($id,$code,$label,'invalid',null,null,$source,'金额格式或精度无法可靠计算。',$unit);return;}
        $expected=$operation==='multiply'?'1':'0';foreach($values as $value)$expected=$operation==='multiply'?bcmul($expected,$value,self::SCALE):bcadd($expected,$value,self::SCALE);
        $delta=bcsub($left,$expected,self::SCALE);$absolute=ltrim($delta,'-');
        $match=$operation==='gte'?bccomp($delta,'0',self::SCALE)>=0:bccomp($absolute,$tolerance,self::SCALE)<=0;
        $this->add($id,$code,$label,$match?'matched':'difference',$this->display($left),$this->display($expected),$source,$note,$unit,$this->display($delta));
    }
    private function flowKey(array $v):?string {
        $values=array_map(fn($k)=>$this->decimal($v[$k]??null),['amount','balance_before','balance_after']);
        if(in_array(null,$values,true))return null;
        return json_encode([(int)$v['user_id'],$this->display(bcadd(ltrim($values[0],'-'),'0',self::SCALE)),$this->display(bcadd($values[1],'0',self::SCALE)),$this->display(bcadd($values[2],'0',self::SCALE))]);
    }
    private function add(int $id,string $code,string $label,string $state,?string $actual,?string $expected,string $source,?string $note=null,?string $unit=null,?string $delta=null):void {
        $this->checks[]=['legacy_id'=>$id,'code'=>$code,'label'=>$label,'state'=>$state,'actual'=>$actual,'expected'=>$expected,'delta'=>$delta,'unit'=>$unit,'source'=>$source,'note'=>$note];
    }
}
