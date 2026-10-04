<?php
namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\{Cycle,Decimal};
use App\Services\Umi\Business\Ledger;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;

/** Explicit, once-only balance conversion. Source history and identity remain auditable. */
final class AccountCutover
{
    public function preview(?int $batchId = null): array
    {
        $snapshots=app(CaptureSnapshots::class); $batchId ??= $snapshots->latestId();
        $batch=$batchId ? DB::table('umi_v2_capture_batches')->find($batchId) : null;
        $sources=$batch ? DB::table('umi_v2_capture_accounts')->where('batch_id',$batchId)->orderBy('legacy_id')->get()->keyBy('legacy_id') : collect();
        $rows=[]; $total='0'; $seen=[];
        $accounts=Schema::hasTable('umi_business_accounts') ? DB::table('umi_business_accounts')->where('fixture',false)->orderBy('id')->get() : collect();
        foreach ($accounts as $account) {
            $legacy=null; $source=$sources->get($account->legacy_id); if ($source) $seen[]=(int)$account->legacy_id;
            if (DB::table('umi_v2_account_merges')->where('business_account_id',$account->id)->exists()) continue;
            $balances=DB::table('umi_business_balances')->where('bucket','like','account:'.$account->id.':%')
                ->orderBy('asset')->orderBy('bucket')->get(['bucket','asset','amount'])->all();
            $amount='0'; $issues=[]; $local=[];
            if (!$account->user_id || !DB::table('users')->where('id',$account->user_id)->exists()) $issues[]='identity';
            if (($account->legacy_id!==null && $accounts->where('legacy_id',$account->legacy_id)->count()>1) || ($account->user_id!==null && $accounts->where('user_id',$account->user_id)->count()>1)) $issues[]='duplicate_identity';
            if (Schema::hasTable('umi_legacy_accounts')) {
                $legacy=DB::table('umi_legacy_accounts')->where('legacy_id',$account->legacy_id)->first();
                if ($legacy && (int)$legacy->user_id!==(int)$account->user_id) $issues[]='legacy_identity';
                $bound=$account->user_id!==null ? DB::table('umi_legacy_accounts')->where('user_id',$account->user_id)->first() : null;
                if ($bound && (int)$bound->legacy_id!==(int)$account->legacy_id) $issues[]='legacy_identity';
            }
            if (!$source) $issues[]='missing_snapshot_account';
            foreach ($balances as $b) {
                if (Decimal::cmp((string)$b->amount,'0')<0) $issues[]='negative_balance';
                if ($b->asset==='UMI') {
                    $amount=Decimal::add($amount,(string)$b->amount);
                    $local[substr($b->bucket,strlen('account:'.$account->id.':'))]=Decimal::display((string)$b->amount);
                } elseif (Decimal::cmp((string)$b->amount,'0')!==0) $issues[]='non_umi_balance';
            }
            $expected=$source ? json_decode($source->balances_json,true,512,JSON_THROW_ON_ERROR) : [];
            $parent=(int)($account->legacy_parent_id??0);
            if ($account->parent_id) $parent=(int)($accounts->firstWhere('id',$account->parent_id)?->legacy_id??$parent);
            if ($source) {
                foreach (array_unique([...array_keys($local),...array_keys($expected)]) as $pocket)
                    if (Decimal::cmp($local[$pocket]??'0',$expected[$pocket]??'0')!==0) $issues[]='balance:'.$pocket;
                $original=json_decode($source->source_json,true,512,JSON_THROW_ON_ERROR);
                if (isset($legacy) && $legacy && (int)$legacy->parent_legacy_id!==$parent) $issues[]='parent_identity';
                if ($parent !== (int)($original['resources']['detail']['inviter']['user_id']??0)) $issues[]='parent_identity';
            }
            $total=Decimal::add($total,$amount);
            $rows[]=['account_id'=>$account->id,'user_id'=>$account->user_id,'legacy_id'=>$account->legacy_id??null,
                'parent_legacy_id'=>$parent,'balances'=>$balances,'principal_umi'=>$amount,
                'source_principal_umi'=>$source ? (string)$source->principal_umi : null,'issues'=>array_values(array_unique($issues))];
        }
        foreach ($sources as $source) if (!in_array((int)$source->legacy_id,$seen,true))
            $rows[]=['account_id'=>null,'user_id'=>null,'legacy_id'=>$source->legacy_id,'principal_umi'=>'0',
                'source_principal_umi'=>(string)$source->principal_umi,'issues'=>['missing_business_account']];
        $sha=hash('sha256',json_encode(['batch_id'=>$batchId,'manifest'=>$batch?->manifest_sha256,'rows'=>$rows],JSON_THROW_ON_ERROR));
        $approval=$batch ? DB::table('umi_v2_capture_approvals')->where('batch_id',$batchId)->where('source_sha256',$sha)->first() : null;
        return ['accounts'=>count($rows),'principal_umi'=>Decimal::display($total),
            'blocked'=>count(array_filter($rows,fn($r)=>!empty($r['issues']))),'sha256'=>$sha,
            'source_batch'=>$batch ? ['id'=>$batch->id,'manifest_sha256'=>$batch->manifest_sha256,'member_count'=>$batch->member_count,
                'started_at'=>$batch->started_at,'finished_at'=>$batch->finished_at,'principal_umi'=>(string)$batch->principal_umi] : null,
            'latest_batch'=>$batch && (int)$batch->id===$snapshots->latestId(),'approved'=>(bool)$approval,
            'approval'=>$approval,'differences'=>array_values(array_filter($rows,fn($r)=>!empty($r['issues'])))];
    }

    public function run(string $expectedHash,int $quoteId,string $startsOn,int $actorId, ?int $batchId = null): array
    {
        FundedRuntime::requireEnabled();
        if (!preg_match('/^[a-f0-9]{64}$/D',$expectedHash)
            || !CarbonImmutable::hasFormat($startsOn,'Y-m-d')) throw new DomainException('接续确认参数无效。');
        return DB::transaction(function()use($expectedHash,$quoteId,$startsOn,$actorId,$batchId):array {
            $s=DB::table('umi_v2_live_settings')->where('id',1)->lockForUpdate()->first();
            $prior=DB::table('umi_v2_account_merge_batches')->where('source_sha256',$expectedHash)->first();
            if ($prior) {
                if ((int)$prior->capture_batch_id!==$batchId || (int)$prior->quote_id!==$quoteId || $prior->starts_on!==$startsOn || (int)$prior->actor_id!==$actorId)
                    throw new DomainException('该数据已按其他参数完成接续。');
                return json_decode($prior->result_json,true,512,JSON_THROW_ON_ERROR)+['replayed'=>true];
            }
            if (!$s->account_cutover_enabled) throw new DomainException('请先核对全新数据，再启用账户本金接续。');
            $state=DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();
            if (!$state?->rule_id) throw new DomainException('接续来源账务不完整。');
            if (DB::table('umi_business_plans')->where('status','active')->exists()
                || DB::table('umi_business_unstakes')->whereNull('completed_at')->exists()) {
                throw new DomainException('仍有进行中的订单或解锁记录，请先核对后再接续。');
            }
            if (!$batchId) throw new DomainException('必须选择已核定的采集批次。');
            DB::table('umi_business_accounts')->where('fixture',false)->orderBy('id')->lockForUpdate()->get();
            DB::table('umi_business_balances')->where('bucket','like','account:%')->orderBy('bucket')->orderBy('asset')->lockForUpdate()->get();
            $preview=$this->preview($batchId);
            if (!$preview['source_batch'] || !$preview['latest_batch'] || !$preview['approved']) throw new DomainException('采集来源未经核定、已更新或核定后余额有变化，请重新核对。');
            if ($preview['blocked'] || !hash_equals($expectedHash,$preview['sha256'])) throw new DomainException('接续数据发生变化或有待核对项目，请重新核对。');
            $quote=DB::table('umi_v2_live_quotes')->where('id',$quoteId)->where('asset','UMI_USDT')->whereIn('source',[SpotQuote::SOURCE,BestAskQuote::SOURCE])->first();
            if (!$quote) throw new DomainException('请选择来自 Deepro UMI/USDT 的业务报价凭证。');
            $cutoff=CarbonImmutable::parse($preview['approval']->cutoff_at);
            $firstAsk=DB::table('umi_v2_live_quotes')->where('asset','UMI_USDT')->where('source',BestAskQuote::SOURCE)->min('observed_at');
            if ($quote->source===SpotQuote::SOURCE && $firstAsk && $cutoff->gte(CarbonImmutable::parse($firstAsk))) throw new DomainException('当前接续必须使用盘口卖一价凭证。');
            $quoted=CarbonImmutable::parse($quote->observed_at);
            if ($quoted->gt($cutoff) || $quoted->lt($cutoff->subSeconds($quote->source===BestAskQuote::SOURCE?BestAskQuote::MAX_HISTORY_AGE:300))) throw new DomainException('接续报价必须是截止时点之前的有效报价凭证。');
            if ($startsOn<$cutoff->setTimezone(config('umi-v2.timezone'))->addDay()->toDateString()) throw new DomainException('接续起算日必须在核定截止业务日之后。');
            if (!$preview['accounts']) throw new DomainException('没有待接续的账户。');
            $last=DB::table('umi_v2_live_daily_runs')->max('business_date');
            if ($last && $startsOn<=$last) throw new DomainException('起算日已经完成日结，请先核对接续起算日。');
            $policy=app(FundedIntake::class)->policy(); $count=0; $active=0; $pending=0;
            foreach (DB::table('umi_business_accounts')->where('fixture',false)->orderBy('id')->get() as $a) {
                if (DB::table('umi_v2_account_merges')->where('business_account_id',$a->id)->exists()) continue;
                $member=app(MemberEnrollment::class)->activateHistorical((int)$a->user_id,'cutover:member:'.$a->id,$actorId);
                $balances=DB::table('umi_business_balances')->where('bucket','like','account:'.$a->id.':%')
                    ->orderBy('asset')->orderBy('bucket')->lockForUpdate()->get();
                $amount='0'; foreach ($balances as $b) if ($b->asset==='UMI') $amount=Decimal::add($amount,(string)$b->amount);
                $snapshot=$balances->toJson(); $op=(string)Str::uuid();
                DB::table('umi_business_operations')->insert(['id'=>$op,'scope'=>'v2-cutover','request_key'=>'account:'.$a->id,
                    'request_hash'=>hash('sha256',$snapshot),'type'=>'v2_cutover','actor_id'=>$actorId,'account_id'=>$a->id,
                    'rule_id'=>$state->rule_id,'business_date'=>$state->business_date,'details'=>json_encode(['member_id'=>$member->id,'principal_umi'=>$amount]),'created_at'=>FundedTime::database(now())]);
                foreach ($balances as $b) if ($b->asset==='UMI' && Decimal::cmp((string)$b->amount,'0')>0) {
                    app(Ledger::class)->move($op,$b->bucket,'system:custody',(string)$b->amount,'UMI','统一账户本金接续');
                }
                $cycleId=null; $status='empty';
                if (Decimal::cmp($amount,'0')>0 && Decimal::cmp(Decimal::mul($amount,(string)$quote->price),'100')>=0) {
                    $opening=Cycle::open('new',(string)$member->id,$amount,(string)$quote->price,(string)$policy->id);
                    $cycleId=DB::table('umi_v2_cycles')->insertGetId(['member_id'=>$member->id,'policy_version_id'=>$policy->id,
                        'cycle_number'=>1+(int)DB::table('umi_v2_cycles')->where('member_id',$member->id)->max('cycle_number'),
                        'activation_request_key'=>'live:cutover:'.$a->id,'multiplier'=>$opening->multiple,'principal_umi'=>$amount,
                        'usd_quote_per_umi'=>$quote->price,'principal_usd'=>$opening->valueUsdt,'cap_umi'=>$opening->quota,
                        'status'=>'active','starts_on'=>$startsOn,'created_at'=>FundedTime::database(now()),'updated_at'=>FundedTime::database(now())]);
                    DB::table('umi_v2_live_intents')->insert(['member_id'=>$member->id,'request_key'=>'cutover:'.$a->id,'source'=>'account_cutover',
                        'status'=>'active','amount_umi'=>$amount,'umi_usd_quote'=>$quote->price,'quote_source'=>$quote->source.':'.$quote->source_ref,
                        'quote_at'=>$quote->observed_at,'cycle_id'=>$cycleId,'created_at'=>FundedTime::database(now()),'updated_at'=>FundedTime::database(now())]);
                    $status='active'; $active++;
                } elseif (Decimal::cmp($amount,'0')>0) {
                    DB::table('umi_v2_pending_principals')->insert(['member_id'=>$member->id,'amount_umi'=>$amount,'created_at'=>FundedTime::database(now()),'updated_at'=>FundedTime::database(now())]);
                    $status='pending_principal'; $pending++;
                }
                DB::table('umi_v2_account_merges')->insert(['business_account_id'=>$a->id,'member_id'=>$member->id,'user_id'=>$a->user_id,
                    'legacy_id'=>$a->legacy_id??null,'principal_umi'=>$amount,'quote_id'=>$quoteId,'cycle_id'=>$cycleId,'status'=>$status,
                    'balances_json'=>$snapshot,'source_sha256'=>hash('sha256',$snapshot),'operation_id'=>$op,'actor_id'=>$actorId,'created_at'=>FundedTime::database(now())]);
                $count++;
            }
            $result=['capture_batch_id'=>$batchId,'policy_version_id'=>$policy->id,'source_sha256'=>$expectedHash,'accounts'=>$count,'active_cycles'=>$active,'pending_principals'=>$pending,'principal_umi'=>$preview['principal_umi']];
            DB::table('umi_v2_account_merge_batches')->insert(['source_sha256'=>$expectedHash,'capture_batch_id'=>$batchId,'quote_id'=>$quoteId,
                'starts_on'=>$startsOn,'actor_id'=>$actorId,'result_json'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>FundedTime::database(now())]);
            return $result+['replayed'=>false];
        },3);
    }
}
