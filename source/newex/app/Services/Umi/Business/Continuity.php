<?php
namespace App\Services\Umi\Business;

use App\Models\Umi\LegacyAccount;
use App\Services\Umi\{LegacyIntegrity,LegacyActivation};
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;

/** Convert the approved 2026-09-16 account snapshot into an opening journal.
 * Historical orders remain evidence, never replayed as new purchases.
 */
final class Continuity
{
    public const CUTOFF='2026-09-16';
    public const ISSUE_LIMIT='100000000';
    public function __construct(private Engine $engine){}
    private function decimal(mixed $value):string {return Amount::valid($value);}
    private function equal(string $a,string $b):bool {return Amount::cmp(ltrim(Amount::sub($a,$b),'-'),'0.000000000001')<=0;}
    public function preview():array {
        $legacy=LegacyAccount::orderBy('legacy_id')->get();$summaries=DB::table('umi_legacy_summaries')->get()->keyBy('legacy_id');
        $rows=[];$fingerprints=[];$opening='0';$daily='0';
        foreach($legacy as $a){
            $p=$a->profile;$s=$summaries[$a->legacy_id]??null;$errors=[];$balances=[];
            $q=$p['quota_summary']??[];$perf=$p['performance']??[];
            $row=['legacy_id'=>$a->legacy_id,'parent_legacy_id'=>$a->parent_legacy_id,'user_id'=>$a->activation_status==='activated'?$a->user_id:null,
                'quota_total'=>'0','quota_used'=>'0','personal'=>'0','manual_level'=>0,'level'=>0,
                'reward_excluded'=>true,'daily_release'=>'0','balances'=>[],'status'=>'review','issues'=>[]];
            $fingerprints[]=[$a->legacy_id,$a->source_hash,$s?->source_hash];
            try {
                if(app(LegacyIntegrity::class)->account($a))throw new \RuntimeException(__('原资料完整性校验失败'));
                foreach(['quota_total'=>'total_quota','quota_used'=>'used_quota'] as $k=>$field)$row[$k]=$this->decimal($q[$field]??null);
                if(Amount::cmp($row['quota_used'],$row['quota_total'])>0)throw new \RuntimeException(__('历史已用额度大于总额度'));
                $row['personal']=$this->decimal($perf['personal_performance']??null);
                $row['manual_level']=(int)ltrim((string)($perf['manual_level']??'0'),'V');
                $row['level']=(int)ltrim((string)($perf['current_level']??'0'),'V');
                if($row['manual_level']>9||$row['level']>9)throw new \RuntimeException(__('原等级超出规则范围'));
                $row['reward_excluded']=(bool)($perf['exclude_from_rewards']??false);
                if(!$s)throw new \RuntimeException(__('缺少资产拆分 CSV；保留关系和额度，余额及旧计划暂不入账'));
                $raw=Crypt::decryptString($s->payload);
                if(!hash_equals($s->source_hash,hash('sha256',$raw)))throw new \RuntimeException(__('CSV 完整性校验失败'));
                $v=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                foreach(['quota_total'=>'总额度(UMI)','quota_used'=>'已用额度(UMI)'] as $k=>$field)
                    if(!$this->equal($row[$k],$this->decimal($v[$field]??null)))throw new \RuntimeException(__('原资料与 CSV 额度不一致'));
                $fields=['main'=>'[核查]主余额(UMI)','linear'=>'[核查]理财收益余额(UMI)','team'=>'[核查]团队收益余额(UMI)','referral'=>'[核查]直推收益余额(UMI)','treasure'=>'[核查]复投宝本金(UMI)','reserve'=>'[核查]备用金(UMI)'];
                foreach($fields as $pocket=>$field)$balances[$pocket]=$this->decimal($v[$field]??null);
                $total=Amount::sum(array_intersect_key($balances,array_flip(['main','linear','team','referral','treasure'])));
                if(!$this->equal($total,$this->decimal($v['DApp可提资产(UMI)']??null))||!$this->equal($total,$this->decimal($p['dapp_balance_umi']??null)))throw new \RuntimeException(__('可提资产拆分与总额不一致'));
                $row['daily_release']=$this->decimal($v['当前待释放理财收益(UMI)']??null);
                if(!$this->equal($row['daily_release'],Amount::add($this->decimal($v['购买待释放理财收益(UMI)']??null),$this->decimal($v['赠送待释放理财收益(UMI)']??null))))throw new \RuntimeException(__('购买与赠送待释放日量不一致'));
                if($a->legacy_status===0)throw new \RuntimeException(__('原账户已禁用，等待核查后接续'));
                $row['balances']=$balances;$row['status']='ready';
                $opening=Amount::add($opening,Amount::sum($balances));$daily=Amount::add($daily,$row['daily_release']);
            }catch(\Throwable $e){$errors[]=$e instanceof \Illuminate\Validation\ValidationException?'原资料缺少有效的非负金额字段':$e->getMessage();$row['daily_release']='0';}
            if($a->parent_legacy_id&&!$legacy->contains('legacy_id',$a->parent_legacy_id))$row['outside_parent']=$a->parent_legacy_id;
            $row['issues']=$errors;$rows[]=$row;
        }
        $fingerprint=hash('sha256',json_encode($fingerprints,JSON_THROW_ON_ERROR));
        $already=DB::table('umi_continuity_batches')->where('fingerprint',$fingerprint)->first();
        return ['cutoff'=>self::CUTOFF,'fingerprint'=>$fingerprint,'rows'=>$rows,'accounts'=>count($rows),
            'ready'=>count(array_filter($rows,fn($r)=>$r['status']==='ready')),'review'=>count(array_filter($rows,fn($r)=>$r['status']!=='ready')),
            'opening_umi'=>$opening,'daily_linear_umi'=>$daily,'issue_limit'=>self::ISSUE_LIMIT,
            'imported'=>$already!==null,'batch_id'=>$already?->id,
            'notes'=>['按 9 月 16 日账户待释放日量续发，以账户剩余额度为上限；未伪造逐笔历史已释放量。','范围外上级保留原 ID，不改挂靠；待补齐其原资料。','快照未含完整 VIP 质押、待解押及 USDT 期初证明，这些项不凭估值入账。','UMI 为站内发行记账；USDT 不增发。总发行上限一亿 UMI，包含本批期初权益。']];
    }
    public function import(int $actor,string $fingerprint,string $reason,string $key):array {
        if(mb_strlen(trim($reason))<10)$this->engine->fail('reason',__('请填写历史接续依据。'));
        return $this->engine->run(null,$actor,'continuity',['fingerprint'=>$fingerprint,'reason'=>$reason],$key,function($op)use($fingerprint,$actor){
            $p=$this->preview();if(!hash_equals($p['fingerprint'],$fingerprint))$this->engine->fail('fingerprint',__('资料已更新，请重新预览。'));
            if($p['imported'])$this->engine->fail('fingerprint',__('这批历史权益已经接续，不能再次入账。'));
            if(!$p['accounts'])$this->engine->fail('snapshot',__('没有可接续的历史资料。'));
            if(DB::table('umi_continuity_batches')->exists())$this->engine->fail('snapshot',__('已存在历史接续批次；新增资料须走差异处理，不能整批重放。'));
            // Rewinding a clock after settlement would replay rewards for existing users.
            if(DB::table('umi_business_days')->exists()||DB::table('umi_business_rewards')->exists()||DB::table('umi_business_plans')->exists())$this->engine->fail('cutoff',__('已有新业务结算或计划，须先核对切换日期差异。'));
            if(Amount::cmp($p['opening_umi'],self::ISSUE_LIMIT)>0)$this->engine->fail('amount',__('历史期初超过已授权的一亿 UMI 发行额度。'));
            foreach($p['rows'] as $row)if($row['user_id']&&$this->engine->owned($row['user_id']))$this->engine->fail('account',__('原用户已存在另一份业务账户，须核对后合并。'));
            $this->engine->rules->continueLegacy($actor);
            $batch=(string)Str::uuid();$ids=[];$todo=$p['rows'];$legacyIds=array_column($todo,'legacy_id');
            while($todo){$progress=false;foreach($todo as $i=>$r){$parent=$r['parent_legacy_id'];if($parent&&in_array($parent,$legacyIds,true)&&!isset($ids[$parent]))continue;
                $id=DB::table('umi_business_accounts')->insertGetId(['user_id'=>$r['user_id'],'legacy_id'=>$r['legacy_id'],'legacy_parent_id'=>$parent,'parent_id'=>$ids[$parent]??null,'code'=>'UMI-'.$r['legacy_id'],'fixture'=>false,'reward_excluded'=>$r['reward_excluded'],'manual_level'=>$r['manual_level'],'level'=>$r['level'],'personal'=>$r['personal'],'quota_total'=>$r['quota_total'],'quota_used'=>$r['quota_used'],'created_at'=>now(),'updated_at'=>now()]);
                $ids[$r['legacy_id']]=$id;DB::table('umi_continuity_openings')->insert(['account_id'=>$id,'legacy_id'=>$r['legacy_id'],'batch_id'=>$batch,'cutoff'=>self::CUTOFF,'status'=>$r['status'],'snapshot'=>json_encode($r,JSON_THROW_ON_ERROR),'quota_total'=>$r['quota_total'],'quota_used'=>$r['quota_used'],'daily_release'=>$r['daily_release'],'created_at'=>now()]);
                unset($todo[$i]);$progress=true;
            }if(!$progress)$this->engine->fail('parent',__('原关系存在循环，未导入任何账户。'));}
            // The issuance journal includes opening obligations and future distribution inventory.
            $this->engine->ledger->move($op,'system:issuance','system:distribution',self::ISSUE_LIMIT,'UMI','UMI 站内发行额度（非链上充值）');
            foreach($p['rows'] as $r)foreach($r['balances'] as $pocket=>$amount)$this->engine->ledger->move($op,'system:distribution',Ledger::bucket($ids[$r['legacy_id']],$pocket),$amount,'UMI','9 月 16 日期初权益接续');
            DB::table('umi_continuity_batches')->insert(['id'=>$batch,'fingerprint'=>$fingerprint,'cutoff'=>self::CUTOFF,'operation_id'=>$op,'summary'=>json_encode(array_diff_key($p,['rows'=>true]),JSON_THROW_ON_ERROR),'created_at'=>now()]);
            DB::table('umi_business_state')->where('id',1)->update(['business_date'=>self::CUTOFF]);
            $this->engine->team->recalculate($this->engine->rules->current()['rules']);
            if(!$this->engine->ledger->audit()['ok'])throw new \LogicException(__('历史接续对账失败，整批已回滚。'));
        });
    }
    public function attach(int $user):?object {
        $legacy=LegacyAccount::where('user_id',$user)->where('activation_status','activated')->first();
        if(!$legacy)return $this->engine->owned($user);
        return DB::transaction(function()use($legacy,$user){
            DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();
            $a=DB::table('umi_business_accounts')->where('legacy_id',$legacy->legacy_id)->lockForUpdate()->first();
            if($a&&$a->user_id===null){
                if($this->engine->owned($user))$this->engine->fail('account',__('此交易所账户已有独立 UMI 账户，请先核对归属。'));
                DB::table('umi_business_accounts')->where('id',$a->id)->update(['user_id'=>$user,'updated_at'=>now()]);
            }
            return $this->engine->owned($user);
        });
    }
}
