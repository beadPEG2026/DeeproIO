<?php
namespace App\Services\Umi\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
final class Rules {
    public function live():bool {return (bool)config('umi-business.funded_live');}
    public function writable():bool {return $this->live() || (app()->environment(['local','testing']) && (bool)config('umi-business.enabled'));}
    public function readable():bool {return $this->writable() || (bool)config('umi-business.cloud_read_only');}
    public function assertReadable():void {abort_unless($this->readable(),503,__('UMI 新业务尚未在此环境开放。'));}
    public function assertLocal():void {abort_unless($this->writable(),503,__('UMI 资金接续尚未完成验收，当前仅供查看。'));}
    public function current():array {$state=DB::table('umi_business_state')->find(1);if(!$state?->rule_id)return ['id'=>null,'rules'=>config('umi-business.defaults')];$row=DB::table('umi_business_rules')->find($state->rule_id);return ['id'=>$row->id,'rules'=>json_decode($row->rules,true),'reason'=>$row->reason,'created_at'=>$row->created_at];}
    /** Settlement configuration is selected by its business date, never today's editor state. */
    public function forDay(string $day):array {
        foreach(DB::table('umi_business_rules')->orderByDesc('id')->get() as $row){
            $rules=json_decode($row->rules,true);
            if(!isset($rules['settlement_effective_on'])||$rules['settlement_effective_on']<=$day)
                return ['id'=>$row->id,'rules'=>$rules];
        }
        throw new \LogicException('No UMI rule version for business date '.$day);
    }
    /** Caller holds the single state-row lock, shared by all financial mutations. */
    public function ensure():array {if(!DB::transactionLevel())throw new \LogicException('Rules require transaction');$r=$this->current();if($r['id'])return $r;$rules=config('umi-business.defaults');if($this->live()){$rules['assumptions']=array_values(array_filter($rules['assumptions'],fn($text)=>!str_starts_with($text,'业务日期仅用于本地验收')));$rules['assumptions']=array_map(fn($text)=>str_replace('此项仅为本地补全方案','此项为用户确认的新版本推算规则',$text),$rules['assumptions']);$rules['assumptions'][]='业务日按 Asia/Shanghai 运行，宝本金从存入下一日计息，已经结束的业务日由系统顺序结算。';$rules['assumptions'][]='2026-09-17 用户确认采用推算规则作为新版本。仅作用于新业务，原历史数据保持不变。奖励与兑换从实充资金池支付，余额不足整笔回滚。';}$id=$this->append($rules,$this->live()?'用户确认的推算新版本：实充资金池支付，不改原历史权益。':'依据 2026-09-17 逆推报告建立本地验收版本；不作为旧权益续算授权。',null);return ['id'=>$id,'rules'=>$rules];}
    public function continueLegacy(int $actor):int {
        if(!DB::transactionLevel())throw new \LogicException('Continuity rules require transaction');
        $r=$this->current()['rules'];
        $r['assumptions']=array_values(array_filter($r['assumptions'],fn($text)=>!str_contains($text,'仅作用于新业务')));
        $r['assumptions'][]='用户确认以 2026-09-16 原资料接续：原余额、额度及关系登记一次，自次日按快照日释放量和剩余额度续算。历史订单不重复执行。';
        $r['assumptions'][]='UMI 收益从已登记的站内发行池支付，总发行上限一亿 UMI；这不是链上充值。USDT 兑换仍以资金账户实际转入的库存为限。';
        return $this->append($r,'2026-09-18 用户确认按 9 月 16 日原资料开放历史权益接续；费率及计算参数沿用现有版本。',$actor?:null);
    }
    private function append(array $rules,string $reason,?int $actor):int {$json=json_encode($rules,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$id=DB::table('umi_business_rules')->insertGetId(['rules'=>$json,'digest'=>hash('sha256',$json),'reason'=>$reason,'actor_id'=>$actor,'created_at'=>now()]);DB::table('umi_business_state')->where('id',1)->update(['rule_id'=>$id]);return $id;}
    public function update(array $changes,string $reason,int $actor):int {
        $this->assertLocal();if(mb_strlen(trim($reason))<10)throw ValidationException::withMessages(['reason'=>__('请填写至少 10 个字的变更依据。')]);
        return DB::transaction(function()use($changes,$reason,$actor){DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();$r=$this->ensure()['rules'];
            $rates=['purchase_daily_rate','gift_daily_rate','referral_rate','treasure_daily_rate','treasure_fee','linear_fee','team_fee','referral_fee','swap_fee','transfer_fee','vip_fee','peer_rate'];
            foreach($changes as $key=>$value){
                if(in_array($key,$rates,true)){$value=Amount::valid($value);if(Amount::cmp($value,'1')>0)throw ValidationException::withMessages([$key=>__('比例必须在 0 到 1 之间。')]);}
                elseif($key==='price'){$value=Amount::valid($value,true);}
                elseif($key==='unstake_minutes'){if(filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<1||(int)$value>525600)throw ValidationException::withMessages([$key=>__('等待时间须为 1 到 525600 分钟。')]);$value=(int)$value;}
                elseif($key==='treasure_fee_source'){if(!in_array($value,['proceeds','reserve'],true))throw ValidationException::withMessages([$key=>__('手续费来源无效。')]);}
                elseif($key==='peer_policy'){if(!in_array($value,['nearest_equal_nonrecursive','disabled'],true))throw ValidationException::withMessages([$key=>__('平级策略无效。')]);}
                elseif($key==='peer_min_level'){if(filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<1||(int)$value>9)throw ValidationException::withMessages([$key=>__('平级起始等级须为 1 到 9。')]);$value=(int)$value;}
                elseif(in_array($key,['tiers','ranks','vip_thresholds'],true)){$value=$this->structure($key,$value);}
                else throw ValidationException::withMessages(['rules'=>__('不支持的规则字段：').$key]);
                $r[$key]=$value;
            }
            $r['settlement_effective_on']=app(ReleaseSchedule::class)->effectiveDay();
            return $this->append($r,trim($reason),$actor);
        },3);
    }
    private function structure(string $key,mixed $value):array {
        $bad=fn()=>throw ValidationException::withMessages([$key=>__('档位配置无效，请检查数量、顺序、比例及边界。')]);
        if(!is_array($value)||!array_is_list($value))$bad();
        $result=[];$previous=null;
        if($key==='vip_thresholds'){
            if(count($value)!==4)$bad();
            foreach($value as $min){$min=Amount::valid($min,true);if($previous!==null&&Amount::cmp($min,$previous)<=0)$bad();$result[]=$min;$previous=$min;}
        }elseif($key==='tiers'){
            if(count($value)!==3)$bad();
            foreach($value as $i=>$tier){
                if(!is_array($tier))$bad();$min=Amount::valid($tier['min']??null,true);$max=($tier['max']??null)===null?null:Amount::valid($tier['max'],true);$multiple=Amount::valid($tier['multiplier']??null,true);
                if(($i>0&&Amount::cmp($min,$previous)!==0)||($i<2&&($max===null||Amount::cmp($max,$min)<=0))||($i===2&&$max!==null)||Amount::cmp($multiple,'5')>0)$bad();
                $result[]=['min'=>$min,'max'=>$max,'multiplier'=>$multiple];$previous=$max;
            }
        }else{
            if(count($value)!==9)$bad();$lastRate='0';
            foreach($value as $i=>$rank){
                if(!is_array($rank)||(string)($rank['level']??'')!==(string)($i+1))$bad();
                $personal=Amount::valid($rank['personal']??null);$small=Amount::valid($rank['small']??null,true);$rate=Amount::valid($rank['rate']??null);
                if(($previous!==null&&Amount::cmp($small,$previous)<=0)||Amount::cmp($rate,'1')>0||Amount::cmp($rate,$lastRate)<0)$bad();
                $result[]=['level'=>$i+1,'personal'=>$personal,'small'=>$small,'rate'=>$rate];$previous=$small;$lastRate=$rate;
            }
        }
        return $result;
    }

}
