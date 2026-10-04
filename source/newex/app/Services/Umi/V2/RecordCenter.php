<?php
namespace App\Services\Umi\V2;
use App\Domain\Umi\V2\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** One allowlisted query for screen, totals and complete exports. Client ownership is mandatory. */
final class RecordCenter
{
    public const DATASETS=['wallet_moves'=>'账户划转凭证','intents'=>'充值订单','cycles'=>'充值轮次','releases'=>'收益明细','transfers'=>'收益划转',
        'withdrawals'=>'提取到 Deepro','lots'=>'销毁任务','points'=>'股票积分','stock_moves'=>'股票份额流水',
        'members'=>'成员目录','stock_accounts'=>'股票账户','quotes'=>'报价记录','daily'=>'日结记录','settings'=>'配置记录','activation'=>'原用户开通记录','recovery'=>'异常恢复任务','recovery_actions'=>'异常恢复操作','ranks'=>'等级变更记录'];
    private const ADMIN_ONLY=['members','stock_accounts','quotes','daily','settings','activation','recovery','recovery_actions','ranks'];
    private const TABLES=['wallet_moves'=>'umi_v2_live_wallet_moves','intents'=>'umi_v2_live_intents','cycles'=>'umi_v2_cycles','releases'=>'umi_v2_release_events',
        'transfers'=>'umi_v2_income_transfers','withdrawals'=>'umi_v2_withdrawals','lots'=>'umi_v2_live_burn_lots',
        'points'=>'umi_v2_stock_point_entries','stock_moves'=>'umi_v2_stock_share_moves','members'=>'umi_v2_members',
        'stock_accounts'=>'umi_v2_stock_share_accounts','quotes'=>'umi_v2_live_quotes','daily'=>'umi_v2_live_daily_runs',
        'settings'=>'umi_v2_live_settings_audit','activation'=>'umi_v2_member_activation_audit','recovery'=>'umi_v2_recovery_tasks','recovery_actions'=>'umi_v2_recovery_actions','ranks'=>'umi_v2_rank_audit'];
    private const FIELDS=[
        'wallet_moves'=>'id,member_id,purpose,from_user_id,to_user_id,from_bucket,to_bucket,amount_umi,created_at',
        'intents'=>'id,member_id,source,status,amount_umi,umi_usd_quote,quote_source,quote_at,deposit_id,cycle_id,created_at',
        'cycles'=>'id,member_id,cycle_number,policy_version_id,principal_umi,principal_usd,multiplier,cap_umi,released_umi,status,starts_on,created_at',
        'releases'=>'id,member_id,cycle_id,source_member_id,kind,business_date,candidate_umi,released_umi,cap_before_umi,cap_after_umi,status,policy_version_id,created_at',
        'transfers'=>'id,member_id,pocket,amount_umi,pending_before_umi,pending_after_umi,available_before_umi,available_after_umi,created_at',
        'withdrawals'=>'id,member_id,status,requested_umi,required_burn_umi,confirmed_burn_umi,paid_umi,payout_ref,paid_at,created_at',
        'lots'=>'id,member_id,purpose,cycle_id,withdrawal_id,amount_umi,status,custody_transfer_id,confirmed_at,created_at',
        'points'=>'id,member_id,withdrawal_id,kind,delta_points,burn_evidence_id,policy_version_id,created_at',
        'stock_moves'=>'id,kind,from_member_id,to_member_id,point_entry_id,shares,exchange_wallet_id,exchange_balance_before,exchange_balance_after,actor_id,created_at',
        'members'=>'id,user_id,member_code,legacy_identity_ref,level,status,joined_at,created_at',
        'stock_accounts'=>'member_id,locked_shares,available_shares,created_at',
        'quotes'=>'id,asset,price,source,source_ref,observed_at,approved_by,created_at',
        'daily'=>'id,business_date,static_rate,policy_version_id,summary_json,actor_id,created_at',
        'settings'=>'id,before_json,after_json,actor_id,created_at',
        'activation'=>'id,user_id,member_id,actor_id,basis,created_at',
        'recovery'=>'id,member_id,kind,reference_id,status,attempts,error_code,last_attempt_at,next_attempt_at,resolved_at,created_at',
        'recovery_actions'=>'id,task_id,action,actor_id,reason,result_json,created_at',
        'ranks'=>'id,member_id,business_date,before_level,after_level,personal_usdt,small_area_usdt,basis_sha256,actor_id,created_at',
    ];

    public function query(array $f, ?int $userId): array
    {
        $name=$f['dataset']??'withdrawals';
        if (!isset(self::DATASETS[$name]) || ($userId!==null && in_array($name,self::ADMIN_ONLY,true)))
            throw new DomainException('该记录类型不可访问。');
        $q=DB::table(self::TABLES[$name].' as r');
        $columns=explode(',',self::FIELDS[$name]); $selected=array_map(fn($c)=>'r.'.$c,$columns);
        $key=$name==='stock_accounts'?'member_id':'id';
        if ($name==='cycles') $q->join('umi_v2_live_intents as i','i.cycle_id','=','r.id');
        if ($name==='withdrawals') {
            $q->leftJoin('umi_v2_live_withdrawal_funding as f','f.withdrawal_id','=','r.id');
            foreach (['from_income_umi','from_wallet_umi','from_deposit_umi','remaining_umi'] as $c) {$columns[]=$c;$selected[]='f.'.$c;}
        }
        if ($name==='lots') {
            $q->leftJoin('umi_v2_live_burn_proofs as p','p.lot_id','=','r.id');
            foreach (['tx_hash','chain_id','finalized_at'] as $c) {$columns[]=$c;$selected[]='p.'.$c;}
        }
        if ($name==='points') {
            $q->leftJoin('umi_v2_live_point_terms as t','t.point_entry_id','=','r.id');
            foreach (['eligible_at','unlock_at','confirmed_at','tradable_at'] as $c) {$columns[]=$c;$selected[]='t.'.$c;}
            $columns[]='term_status'; $selected[]='t.status as term_status';
        }
        if ($userId===null && !empty($f['member_id']) && !empty($f['root_member_id'])) throw new DomainException('单个成员与伞下范围请择一查询。');
        $scope=null;
        if ($userId!==null) {
            $id=DB::table('umi_v2_members')->where('user_id',$userId)->value('id');
            if (!$id) $q->whereRaw('1 = 0'); else $scope=[(int)$id];
        } elseif (!empty($f['root_member_id'])) {
            $team=app(TeamOverview::class)->inspect((int)$f['root_member_id']);
            $scope=[(int)$f['root_member_id'],...array_column($team['rows'],'id')];
        } elseif (!empty($f['member_id'])) $scope=[(int)$f['member_id']];
        if ($scope!==null) {
            if ($name==='stock_moves') $q->where(fn($q)=>$q->whereIn('r.from_member_id',$scope)->orWhereIn('r.to_member_id',$scope));
            elseif ($name==='members') $q->whereIn('r.id',$scope);
            elseif (in_array('member_id',explode(',',self::FIELDS[$name]),true)) $q->whereIn('r.member_id',$scope);
            else throw new DomainException('该记录类型不支持成员范围，请清除成员筛选。');
        }
        if (!empty($f['withdrawal_id'])) {
            if (!in_array($name, ['withdrawals','lots','points'], true)) throw new DomainException('该记录类型不支持提取订单筛选。');
            $q->where('r.'.($name==='withdrawals'?'id':'withdrawal_id'), (int)$f['withdrawal_id']);
        }
        if (!empty($f['search'])) {
            if ($name!=='members') throw new DomainException('关键字检索仅适用于成员目录。');
            $search=trim($f['search']);
            $q->where(function($q)use($search) {
                $q->where('r.member_code','like','%'.$search.'%')->orWhere('r.legacy_identity_ref','like','%'.$search.'%');
                if (ctype_digit($search)) $q->orWhere('r.id',(int)$search)->orWhere('r.user_id',(int)$search);
            });
        }
        $status=$name==='points'?'t.status':(in_array('status',$columns,true)?'r.status':null);
        $source=match($name){'intents','quotes'=>'r.source','releases','stock_moves','points'=>'r.kind','lots'=>'r.purpose','transfers'=>'r.pocket',default=>null};
        $options=['status'=>$status?(clone $q)->distinct()->orderBy($status)->pluck($status)->filter()->values()->all():[],
            'source'=>$source?(clone $q)->distinct()->orderBy($source)->pluck($source)->filter()->values()->all():[]];
        foreach (['status'=>$status,'source'=>$source] as $filter=>$column) if (!empty($f[$filter])) {
            if (!$column) throw new DomainException('该记录类型不支持此筛选条件。');
            $q->where($column,$f[$filter]);
        }
        $zone=config('umi-v2.timezone','Asia/Shanghai');
        if (!empty($f['date_from'])) $q->where('r.created_at','>=',FundedTime::database(CarbonImmutable::parse($f['date_from'],$zone)->startOfDay()));
        if (!empty($f['date_to'])) $q->where('r.created_at','<',FundedTime::database(CarbonImmutable::parse($f['date_to'],$zone)->addDay()->startOfDay()));
        $max=(clone $q)->max('r.'.$key);
        if ($max!==null) $q->where('r.'.$key,'<=',$max);
        return [$q->select($selected),$columns,$options,$key,$max];
    }

    public function read(array $filters, ?int $userId): array
    {
        return $this->consistent(function()use($filters,$userId) {
            [$q,$columns,$options,$key,$max]=$this->query($filters,$userId);
            $total=(clone $q)->count(); $page=(int)($filters['page']??1); $size=(int)($filters['per_page']??25);
            $totals=$this->totals(clone $q,$columns);
            return ['dataset'=>$filters['dataset']??'withdrawals','columns'=>$columns,'options'=>$options,
                'rows'=>(clone $q)->orderByDesc('r.'.$key)->forPage($page,$size)->get()->map(fn($r)=>FundedTime::forDisplay($r)),
                'total'=>$total,'page'=>$page,'per_page'=>$size,'last_page'=>max(1,(int)ceil($total/$size)),
                'totals'=>$totals,'through_id'=>$max,'queried_at'=>now()->toIso8601String(),'timezone'=>config('umi-v2.timezone','Asia/Shanghai')];
        });
    }

    public function export(array $filters, ?int $userId): array
    {
        return $this->consistent(function()use($filters,$userId) {
            [$q,$columns,,$key,$max]=$this->query($filters,$userId);
            $stream=fopen('php://temp/maxmemory:2097152','w+');
            if ($userId!==null) unset($filters['member_id'],$filters['root_member_id']);
            $meta=['dataset'=>$filters['dataset']??'withdrawals','filters'=>array_diff_key($filters,array_flip(['page','per_page','format'])),
                'total'=>(clone $q)->count(),'through_id'=>$max,'queried_at'=>now()->toIso8601String(),
                'timezone'=>config('umi-v2.timezone','Asia/Shanghai'),'totals'=>$this->totals(clone $q,$columns),
                'hash_scope'=>'UTF-8 CSV header and data rows; excludes metadata and checksum rows'];
            fwrite($stream,"\xEF\xBB\xBF"); fputcsv($stream,['# export_metadata',json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)],',','"','');
            $hash=hash_init('sha256'); $count=0;
            $write=function(array $row)use($stream,$hash):void {
                $line=fopen('php://temp','w+'); fputcsv($line,$row,',','"',''); rewind($line);
                $bytes=stream_get_contents($line); fclose($line); fwrite($stream,$bytes);hash_update($hash,$bytes);
            };
            $write($columns);
            foreach ((clone $q)->orderBy('r.'.$key)->cursor() as $r) {
                $write(array_map(function($c)use($r) {
                    $value=(string)(FundedTime::forDisplay($r->$c??'', $c));
                    // Preserve source values in JSON; CSV neutralizes spreadsheet formulas.
                    return (preg_match('/^[=+@\t\r]/u',$value) || (str_starts_with($value,'-') && !preg_match('/^-\d+(?:\.\d+)?$/D',$value)))?"'".$value:$value;
                },$columns)); $count++;
            }
            if ($count!==$meta['total']) {fclose($stream);throw new DomainException('导出数量发生变化，请重新查询。');}
            $digest=hash_final($hash); fputcsv($stream,['# data_sha256',$digest],',','"','');rewind($stream);
            return ['stream'=>$stream,'sha256'=>$digest,'count'=>$count,'metadata'=>$meta];
        });
    }

    private function totals(Builder $q,array $columns): array
    {
        $sums=[];
        foreach (['amount_umi','principal_umi','principal_usd','released_umi','requested_umi','required_burn_umi','paid_umi','delta_points','shares'] as $c)
            if (in_array($c,$columns,true)) $sums[$c]='0';
        if (!$sums) return [];
        // String arithmetic also remains exact in SQLite, whose SUM uses floating point.
        foreach ($q->cursor() as $row) foreach ($sums as $c=>$v) $sums[$c]=Decimal::add($v,(string)($row->$c??'0'));
        return $sums;
    }

    private function consistent(callable $read): mixed
    {
        return DB::transaction(function()use($read) {
            if (DB::getDriverName()==='pgsql' && DB::transactionLevel()===1)
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            return $read();
        });
    }
}
