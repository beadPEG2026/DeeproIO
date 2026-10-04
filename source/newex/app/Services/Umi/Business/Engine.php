<?php
namespace App\Services\Umi\Business;

use App\Models\Umi\LegacyAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** All UMI mutations share a state lock. No exchange wallet or legacy archive writes. */
final class Engine
{
    public function __construct(public Rules $rules, public Ledger $ledger, public Team $team) {}

    public function run(?int $account, int $actor, string $type, array $input, string $key, callable $work): array
    {
        $this->rules->assertLocal();
        if (!preg_match('/^[a-zA-Z0-9:_-]{8,120}$/D', $key)) $this->fail('request_key',__('操作编号无效，请刷新页面。'));
        ksort($input);
        $hash=hash('sha256',json_encode([$type,$account,$input],JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($account,$actor,$type,$input,$key,$work,$hash) {
            $state=DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();
            if($this->rules->live()) {
                if(DB::table('umi_business_accounts')->where('fixture',true)->exists()) $this->fail('account',__('此环境存在演练账户，请先核对部署数据。'));
                if(!$state->rule_id && !DB::table('umi_business_operations')->exists()) {
                    $state->business_date=now(config('umi-business.defaults.timezone'))->subDay()->toDateString();
                    DB::table('umi_business_state')->where('id',1)->update(['business_date'=>$state->business_date]);
                }
            }
            $scope='actor:'.$actor;
            $old=DB::table('umi_business_operations')->where('scope',$scope)->where('request_key',$key)->first();
            if ($old) {
                if (!hash_equals($old->request_hash,$hash)) $this->fail('request_key',__('操作编号已用于不同内容，请重新发起。'));
                return ['id'=>$old->id,'replayed'=>true];
            }
            if ($state->paused && !in_array($type,['pause','rules'],true)) $this->fail('operation',__('UMI 业务已暂停。'));
            $businessDay=$this->rules->live()&&$type!=='settle'?now(config('umi-business.defaults.timezone'))->toDateString():$state->business_date;
            if($this->rules->live() && $state->business_date<now(config('umi-business.defaults.timezone'))->subDay()->toDateString() && !in_array($type,['settle','custody_transfer','rules','pause','unlock_due','continuity','reward_correction'],true))$this->fail('operation',__('请先完成待结算业务日；管理员可补充资金池后重试结算。'));
            if ($account) abort_unless(DB::table('umi_business_accounts')->where('id',$account)->exists(),404);
            $version=$this->rules->ensure();
            if($type==='settle')$version=$this->rules->forDay($input['day']);
            $op=(string)Str::uuid();
            DB::table('umi_business_operations')->insert(['id'=>$op,'scope'=>$scope,'request_key'=>$key,'request_hash'=>$hash,'type'=>$type,'actor_id'=>$actor,'account_id'=>$account,'rule_id'=>$version['id'],'business_date'=>$businessDay,'details'=>json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>now()]);
            $work($op,$version['rules'],$version['id'],$businessDay);
            return ['id'=>$op,'replayed'=>false];
        },3);
    }
    public function fail(string $key,string $message): never {throw ValidationException::withMessages([$key=>$message]);}
    public function account(int $id): object {return DB::table('umi_business_accounts')->find($id)??abort(404);}
    public function owned(int $user): ?object {return DB::table('umi_business_accounts')->where('user_id',$user)->first();}

    public function enroll(int $user, ?string $parent, string $key, bool $fixture=false): array
    {
        abort_if($fixture && (!app()->environment(['local','testing']) || $this->rules->live()),403);
        $parent=trim((string)$parent);
        return $this->run(null,$user,'enroll',['parent'=>$parent,'fixture'=>$fixture],$key,function() use ($user,$parent,$fixture) {
            if ($this->owned($user)) $this->fail('account',__('UMI 账户已开通，上级不能重新选择。'));
            if (LegacyAccount::where('user_id',$user)->exists()) $this->fail('account',__('原 UMI 账户保留原关系；接续审核完成前不创建另一份权益。'));
            $pid=app(Invitations::class)->resolve($user,$parent,$fixture);
            DB::table('umi_business_accounts')->insert(['user_id'=>$user,'parent_id'=>$pid,'code'=>'U'.strtoupper(Str::random(12)),'fixture'=>$fixture,'created_at'=>now(),'updated_at'=>now()]);
        });
    }

    /** Only CLI acceptance fixtures may be funded; there is no web funding endpoint. */
    public function fund(int $account,string $amount,string $asset,string $key): array
    {
        abort_unless(app()->environment(['local','testing']) && !$this->rules->live(),403);
        $amount=Amount::valid($amount,true);
        if (!in_array($asset,['UMI','USDT'],true)) $this->fail('asset',__('资产无效。'));
        return $this->run($account,0,'fixture_funding',compact('amount','asset'),$key,function($op)use($account,$amount,$asset){
            abort_unless($this->account($account)->fixture,403,__('仅可为专用本地验收账户提供演练额度。'));
            $this->ledger->move($op,'system:funding',Ledger::bucket($account,'main'),$amount,$asset,'本地验收初始资金');
        });
    }

    public function purchase(int $account,int $actor,string $amount,string $key,?array $gift=null): array
    {
        $amount=Amount::valid($amount,true);
        return $this->run($account,$actor,$gift?'gift':'purchase',compact('amount','gift'),$key,function($op,$r,$ruleId,$day)use($account,$amount,$gift){
            $value=Amount::mul($amount,$r['price']);$multiple=null;
            foreach($r['tiers'] as $t) if(Amount::cmp($value,$t['min'])>=0&&($t['max']===null||Amount::cmp($value,$t['max'])<0)) $multiple=$t['multiplier'];
            if($gift) {
                if(mb_strlen(trim($gift['note']??''))<10) $this->fail('note',__('赠送须填写至少 10 个字的依据。'));
                $multiple=Amount::valid($gift['multiplier']??'3',true);
                if(Amount::cmp($multiple,'5')>0)$this->fail('multiplier',__('本地赠送倍数不可超过 5。'));
            }
            if(!$multiple)$this->fail('amount',__('认购价值须达到 100 USDT。'));
            $rate=$gift?Amount::valid($gift['daily_rate']??$r['gift_daily_rate']):$r['purchase_daily_rate'];
            if(Amount::cmp($rate,'1')>0)$this->fail('daily_rate',__('日比例不能超过 100%。'));
            $burnType=$gift?3:1;
            $quota=Amount::mul($amount,$multiple);
            if(!$gift)$this->ledger->move($op,Ledger::bucket($account,'main'),'system:retired',$amount,'UMI','认购扣款（未广播链上销毁）');
            $plan=DB::table('umi_business_plans')->insertGetId(['account_id'=>$account,'operation_id'=>$op,'rule_id'=>$ruleId,'amount'=>$amount,'price'=>$r['price'],'value_usdt'=>$value,'multiplier'=>$multiple,'quota'=>$quota,'daily_rate'=>$rate,'daily_amount'=>Amount::mul($amount,$rate),'source'=>$gift?2:1,'burn_type'=>$burnType,'starts_on'=>\Carbon\CarbonImmutable::parse($day)->addDay()->toDateString(),'note'=>$gift['note']??null,'created_at'=>now()]);
            $a=$this->account($account);
            DB::table('umi_business_accounts')->where('id',$account)->update(['quota_total'=>Amount::add($a->quota_total,$quota),'personal'=>Amount::add($a->personal,$burnType===1?$value:'0'),'updated_at'=>now()]);
            $this->team->recalculate($r,$op,$ruleId,$day);
            if(!$gift&&$a->parent_id) $this->reward($op,$ruleId,$day,(int)$a->parent_id,$account,$plan,'referral',$amount,$r['referral_rate'],['event'=>'purchase','price'=>$r['price']]);
        });
    }

    public function reward(string $op,int $ruleId,string $day,int $recipient,?int $source,?int $plan,string $kind,string $base,string $rate,array $context=[],?string $maximum=null): string
    {
        $a=$this->account($recipient);$expected=Amount::mul($base,$rate);$remaining=Amount::sub($a->quota_total,$a->quota_used);
        $paid=Amount::min($expected,$remaining);if($maximum!==null)$paid=Amount::min($paid,$maximum);
        $reason=null;
        $opening=DB::table('umi_continuity_openings')->where('account_id',$recipient)->first();
        if($opening && $opening->status!=='ready' && !($context['reviewed_correction']??false)) {
            $sourceKey=hash('sha256',json_encode([$recipient,$source,$plan,$day,$kind]));
            if(Amount::cmp($expected,'0')>0)DB::table('umi_pending_rewards')->insertOrIgnore(['source_key'=>$sourceKey,'operation_id'=>$op,'account_id'=>$recipient,'source_account_id'=>$source,'plan_id'=>$plan,'rule_id'=>$ruleId,'business_date'=>$day,'kind'=>$kind,'amount'=>$expected,'context'=>json_encode($context),'reason'=>'历史权益拆分待核对','created_at'=>now()]);
            $paid='0';$reason='legacy_review';
        }
        elseif(in_array($kind,['team','referral','peer'],true)&&$a->reward_excluded){$paid='0';$reason='excluded';}
        elseif(Amount::cmp($paid,$expected)<0)$reason='quota_limited';
        $after=Amount::add($a->quota_used,$paid);
        $pocket=['linear'=>'linear','team'=>'team','referral'=>'referral','interest'=>'treasure','peer'=>'team'][$kind]??throw new \LogicException('Reward kind');
        $this->ledger->move($op,'system:distribution',Ledger::bucket($recipient,$pocket),$paid,'UMI','UMI 收益 '.$kind);
        DB::table('umi_business_accounts')->where('id',$recipient)->update(['quota_used'=>$after,'updated_at'=>now()]);
        DB::table('umi_business_rewards')->insert(['operation_id'=>$op,'account_id'=>$recipient,'source_account_id'=>$source,'plan_id'=>$plan,'kind'=>$kind,'business_date'=>$day,'rule_id'=>$ruleId,'base'=>$base,'rate'=>$rate,'expected'=>$expected,'paid'=>$paid,'quota_before'=>$a->quota_used,'quota_after'=>$after,'reason'=>$reason,'context'=>json_encode($context,JSON_THROW_ON_ERROR),'created_at'=>now()]);
        return $paid;
    }

    public function action(int $account,int $actor,string $action,array $v,string $key): array
    {
        $allowed=['claim','treasure_deposit','treasure_withdraw','reserve_deposit','swap','transfer','stake','unstake'];
        if(!in_array($action,$allowed,true))$this->fail('action',__('操作无效。'));
        $amount=Amount::valid($v['amount']??null,true);
        return $this->run($account,$actor,$action,$v,$key,function($op,$r,$ruleId,$day)use($account,$action,$v,$amount){
            $bucket=fn($p)=>Ledger::bucket($account,$p);
            $move=fn($from,$to,$n,$asset='UMI')=>$this->ledger->move($op,$from,$to,$n,$asset,'UMI '.$action);
            if($action==='claim') {
                $p=$v['pocket']??'';if(!in_array($p,['linear','team','referral'],true))$this->fail('pocket',__('收益类型无效。'));
                $fee=Amount::mul($amount,$r[$p.'_fee']);$move($bucket($p),'system:fees',$fee);$move($bucket($p),$bucket('main'),Amount::sub($amount,$fee));
            } elseif(in_array($action,['treasure_deposit','reserve_deposit','stake'],true)) {
                $to=['treasure_deposit'=>'treasure','reserve_deposit'=>'reserve','stake'=>'vip'][$action];
                $move($bucket('main'),$bucket($to),$amount);
            } elseif($action==='treasure_withdraw') {
                $fee=Amount::mul($amount,$r['treasure_fee']);
                if($r['treasure_fee_source']==='reserve') {
                    $move($bucket('reserve'),'system:fees',$fee);$move($bucket('treasure'),$bucket('main'),$amount);
                    $move('system:points',$bucket('points'),$fee,'POINTS');
                }else{$move($bucket('treasure'),'system:fees',$fee);$move($bucket('treasure'),$bucket('main'),Amount::sub($amount,$fee));}
            } elseif($action==='swap') {
                $from=$v['asset']??'';if(!in_array($from,['UMI','USDT'],true))$this->fail('asset',__('兑换资产无效。'));
                if((string)($v['quote_rule_id']??'')!==(string)$ruleId)$this->fail('quote_rule_id',__('报价已变更，请刷新后重新确认。'));
                $fee=Amount::mul($amount,$r['swap_fee']);$net=Amount::sub($amount,$fee);
                $out=$from==='USDT'?Amount::div($net,$r['price']):Amount::mul($net,$r['price']);
                if(Amount::cmp($out,'0')<=0)$this->fail('amount',__('兑换数量过小。'));
                $move($bucket('main'),'system:fees',$fee,$from);$move($bucket('main'),'system:inventory',$net,$from);
                $move('system:inventory',$bucket('main'),$out,$from==='UMI'?'USDT':'UMI');
            } elseif($action==='transfer') {
                $target=DB::table('umi_business_accounts')->where('code',$v['recipient']??'')->first();
                if(!$target||(int)$target->id===$account)$this->fail('recipient',__('请输入其他有效 UMI 账户编号。'));
                $fee=Amount::mul($amount,$r['transfer_fee']);$move($bucket('main'),'system:fees',$fee);$move($bucket('main'),Ledger::bucket($target->id,'main'),Amount::sub($amount,$fee));
            } elseif($action==='unstake') {
                $move($bucket('vip'),$bucket('unstaking'),$amount);
                // Local clock is explicit; the original account's unlock dates are never replaced.
                $unlock=($this->rules->live()?\Carbon\CarbonImmutable::now($r['timezone']):\Carbon\CarbonImmutable::parse($day,$r['timezone'])->startOfDay())->addMinutes($r['unstake_minutes']);
                DB::table('umi_business_unstakes')->insert(['account_id'=>$account,'operation_id'=>$op,'rule_id'=>$ruleId,'amount'=>$amount,'fee_rate'=>$r['vip_fee'],'unlock_at'=>$unlock->format('Y-m-d H:i:s'),'created_at'=>now()]);
            }
        });
    }

    public function manage(int $actor,string $action,array $input,string $key):array
    {
        if(!in_array($action,['pause','plan','level','quota'],true))$this->fail('action',__('管理操作无效。'));
        if(mb_strlen(trim($input['reason']??''))<10)$this->fail('reason',__('请填写至少 10 个字的依据。'));
        return $this->run(null,$actor,$action,$input,$key,function($op,$r,$ruleId,$day)use($action,$input,$actor){
            if($action==='pause'){DB::table('umi_business_state')->where('id',1)->update(['paused'=>(bool)$input['paused']]);return;}
            if($action==='plan'){
                if(!in_array($input['status']??'', ['active','paused'],true))$this->fail('status',__('状态无效。'));
                $p=DB::table('umi_business_plans')->find($input['plan_id']);abort_unless($p,404);
                if($p->status==='completed')$this->fail('status',__('已完成计划不可重新激活。'));
                $schedule=app(ReleaseSchedule::class);$release=$schedule->resolve('plan',$p,$schedule->effectiveDay());
                $schedule->revise($actor,['target_type'=>'plan','target_id'=>$p->id,'account_id'=>$p->account_id,'expected_revision'=>$release['revision_id'],'daily_rate'=>$release['daily_rate'],'status'=>$input['status'],'reason'=>$input['reason']],'plan-'.$op);
            }else{
                $a=$this->account((int)$input['account_id']);
                if($action==='level'){
                    $level=filter_var($input['manual_level']??null,FILTER_VALIDATE_INT);
                    if($level===false||$level<0||$level>9)$this->fail('manual_level',__('等级须为 0 到 9。'));
                    DB::table('umi_business_accounts')->where('id',$a->id)->update(['manual_level'=>$level,'reward_excluded'=>(bool)($input['reward_excluded']??false)]);$this->team->recalculate($r,$op,$ruleId,$day);
                }else{
                    $amount=Amount::valid($input['amount']??null,true);$direction=$input['direction']??'';
                    if(!in_array($direction,['add','subtract'],true))$this->fail('direction',__('额度方向无效。'));
                    $total=$direction==='add'?Amount::add($a->quota_total,$amount):Amount::sub($a->quota_total,$amount);
                    if(Amount::cmp($total,$a->quota_used)<0)$this->fail('amount',__('额度不能低于已使用额度。'));
                    DB::table('umi_business_accounts')->where('id',$a->id)->update(['quota_total'=>$total]);
                }
            }
        });
    }
}
