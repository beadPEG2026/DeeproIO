<?php
namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\{DB, Schema};

/** Versioned evidence only. Staging never writes identities, legacy balances or rewards. */
final class CaptureSnapshots
{
    public const POCKETS = [
        'main'=>'[核查]主余额(UMI)', 'linear'=>'[核查]理财收益余额(UMI)',
        'team'=>'[核查]团队收益余额(UMI)', 'referral'=>'[核查]直推收益余额(UMI)',
        'treasure'=>'[核查]复投宝本金(UMI)', 'reserve'=>'[核查]备用金(UMI)',
    ];

    public function read(string $directory): array
    {
        $root = realpath($directory);
        if (!$root) throw new DomainException('采集目录不存在。');
        $manifestBytes = file_get_contents($root.'/capture_manifest.json');
        $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
        $verified = [];
        foreach ($manifest['files'] ?? [] as $entry) {
            $path = realpath($root.'/'.$entry['path']);
            if (!$path || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path)
                || !hash_equals($entry['sha256'], hash_file('sha256', $path))
                || filesize($path) !== $entry['size_bytes']) throw new DomainException('采集文件缺失或校验值发生变化。');
            $verified[$entry['path']] = $entry['sha256'];
        }
        // Recheck the exact bytes we consume, avoiding a verify/read race.
        $read = function (string $name) use ($root, $verified): string {
            $path = '最新核验数据/'.$name;
            $bytes = file_get_contents($root.'/'.$path);
            if (!isset($verified[$path]) || !hash_equals($verified[$path], hash('sha256', $bytes)))
                throw new DomainException('正式数据未列入清单或已被改写。');
            return $bytes;
        };
        $payload = [];
        foreach (['users','burn','umi_flow','usdt_flow','usdt_transactions'] as $name) {
            $data = json_decode($read($name.'.json'), true, 512, JSON_THROW_ON_ERROR);
            $ids = array_column($data['list'], 'id');
            if ($data['total'] !== count($ids) || count(array_unique($ids)) !== count($ids))
                throw new DomainException('业务列表计数或主键重复，请重新核验采集包。');
            $payload[$name] = $data;
        }
        foreach (['users_enriched','root_account','project_earnings_summary','collection_receipt'] as $name)
            $payload[$name] = json_decode($read($name.'.json'), true, 512, JSON_THROW_ON_ERROR);
        $csv = fopen('php://temp', 'w+'); fwrite($csv, $read('根账号伞下汇总.csv')); rewind($csv);
        $header = fgetcsv($csv, 0, ',', '"', ''); $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        $accounts = []; $total = '0'; $details = [];
        foreach ($payload['users_enriched'] as $row) {
            if (isset($details[$row['id']])) throw new DomainException('逐人资料重复。');
            $details[$row['id']] = $row;
        }
        while (($values = fgetcsv($csv, 0, ',', '"', '')) !== false) {
            if (count($values) !== count($header)) throw new DomainException('根导出列数不一致。');
            $row = array_combine($header, $values); $id = (int)$row['用户ID'];
            if (!$id || isset($accounts[$id]) || !isset($details[$id])) throw new DomainException('根导出成员重复或缺少逐人资料。');
            $balances = []; $amount = '0';
            foreach (self::POCKETS as $pocket=>$column) {
                $balances[$pocket] = Decimal::amount($row[$column] ?? '');
                $amount = Decimal::add($amount, $balances[$pocket]);
            }
            if (Decimal::cmp(Decimal::sub($amount,$balances['reserve']),Decimal::amount($row['DApp可提资产(UMI)']??''))!==0)
                throw new DomainException('DApp 余额不等于五项余额之和。');
            $accounts[$id] = ['legacy_id'=>$id,'principal_umi'=>$amount,'balances'=>$balances,
                'source'=>['export'=>$row,'resources'=>$details[$id]]];
            $total = Decimal::add($total, $amount);
        }
        fclose($csv);
        $ids = array_map('intval', array_column($payload['users']['list'],'id')); sort($ids);
        $actual = array_keys($accounts); sort($actual); $detailIds = array_keys($details); sort($detailIds);
        $rootIds = array_map('intval',array_column($payload['root_account']['team']['list'],'user_id')); sort($rootIds);
        if (!$ids || $ids !== $actual || $ids !== $detailIds || $ids !== $rootIds) throw new DomainException('用户、根团队与导出范围不一致。');
        foreach ($accounts as $id=>$account) {
            $resource=$details[$id]; $team=$resource['team'];
            if ((int)$resource['detail']['id']!==$id || (int)$team['user_id']!==$id
                || (int)$resource['earnings']['user_id']!==$id || $team['team_count']!==count($team['list'])
                || $resource['earnings']['umbrella_count']!==$team['team_count']) throw new DomainException('成员详情与团队人数不一致。');
        }
        return ['manifest_sha256'=>hash('sha256',$manifestBytes),'source'=>$manifest['source'],
            'started_at'=>$payload['collection_receipt']['started_at'],'finished_at'=>$payload['collection_receipt']['finished_at'],
            'principal_umi'=>$total,'accounts'=>array_values($accounts),'payload'=>$payload];
    }

    public function stage(array $capture, int $actorId): object
    {
        $total='0'; $ids=[];
        if (!preg_match('/^[a-f0-9]{64}$/D',$capture['manifest_sha256']??'')) throw new DomainException('采集清单校验值无效。');
        foreach ($capture['accounts']??[] as $a) {
            $id=(int)$a['legacy_id']; $sum='0';
            if ($id<=0 || isset($ids[$id])) throw new DomainException('快照身份重复或无效。');
            $ids[$id]=true;
            foreach ($a['balances'] as $pocket=>$amount) {
                if (!isset(self::POCKETS[$pocket])) throw new DomainException('采集余额类别未定义。');
                $sum=Decimal::add($sum,Decimal::amount($amount));
            }
            if (Decimal::cmp($sum,Decimal::amount($a['principal_umi']))!==0) throw new DomainException('快照本金与分项不一致。');
            $total=Decimal::add($total,$sum);
        }
        if (!$ids || Decimal::cmp($total,Decimal::amount($capture['principal_umi']))!==0) throw new DomainException('快照总额或范围无效。');
        if (CarbonImmutable::parse($capture['finished_at'])->lt(CarbonImmutable::parse($capture['started_at']))) throw new DomainException('采集时间范围无效。');
        if (!DB::table('users')->where('id',$actorId)->exists()) throw new DomainException('请指定实际操作人。');
        return DB::transaction(function () use ($capture, $actorId) {
            DB::table('umi_v2_live_settings')->where('id',1)->lockForUpdate()->first();
            $encoded=json_encode(['resources'=>$capture['payload'],'accounts'=>$capture['accounts']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $sha=hash('sha256',$encoded);
            $prior=DB::table('umi_v2_capture_batches')->where('manifest_sha256',$capture['manifest_sha256'])->first();
            if ($prior) {
                if (!hash_equals($prior->payload_sha256,$sha)) throw new DomainException('同一采集清单的内容不一致。');
                return $prior;
            }
            $id=DB::table('umi_v2_capture_batches')->insertGetId([
                'manifest_sha256'=>$capture['manifest_sha256'],'payload_sha256'=>$sha,'source'=>$capture['source'],
                'started_at'=>FundedTime::database(CarbonImmutable::parse($capture['started_at'])),
                'finished_at'=>FundedTime::database(CarbonImmutable::parse($capture['finished_at'])),
                'member_count'=>count($capture['accounts']),'principal_umi'=>$capture['principal_umi'],
                'payload_json'=>$encoded,'staged_by'=>$actorId,'created_at'=>FundedTime::database(now()),
            ]);
            foreach ($capture['accounts'] as $a) DB::table('umi_v2_capture_accounts')->insert([
                'batch_id'=>$id,'legacy_id'=>$a['legacy_id'],'principal_umi'=>$a['principal_umi'],
                'balances_json'=>json_encode($a['balances'],JSON_THROW_ON_ERROR),
                'source_json'=>json_encode($a['source'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            ]);
            return DB::table('umi_v2_capture_batches')->find($id);
        },3);
    }

    public function latestId(): ?int
    {
        return Schema::hasTable('umi_v2_capture_batches') ? DB::table('umi_v2_capture_batches')->max('id') : null;
    }

    public function batches(int $page=1): mixed
    {
        return Schema::hasTable('umi_v2_capture_batches') ? DB::table('umi_v2_capture_batches')->orderByDesc('id')
            ->paginate(20,['id','manifest_sha256','source','started_at','finished_at','member_count','principal_umi','staged_by','created_at'],'batch_page',$page)->withQueryString()->through(fn($row)=>FundedTime::forDisplay($row)) : null;
    }

    public function approve(int $id, string $sha, string $cutoff, string $reason, int $actor): object
    {
        return DB::transaction(function () use ($id,$sha,$cutoff,$reason,$actor) {
            DB::table('umi_v2_live_settings')->where('id',1)->lockForUpdate()->first();
            $batch=DB::table('umi_v2_capture_batches')->find($id);
            if (!$batch || $id!==$this->latestId()) throw new DomainException('请选择最新采集批次重新核对。');
            if (!DB::table('users')->where('id',$actor)->exists()) throw new DomainException('复核人不存在。');
            if ((int)$batch->staged_by===$actor) throw new DomainException('请由另一位管理员复核采集批次。');
            if (mb_strlen(trim($reason))<10 || !preg_match('/(?:Z|[+-]\d\d:\d\d)$/D',$cutoff)) throw new DomainException('请填写核对依据与带时区的业务截止时间。');
            $preview=app(AccountCutover::class)->preview($id);
            if ($preview['blocked'] || !hash_equals($preview['sha256'],$sha)) throw new DomainException('身份、余额或范围仍有差异，不能核定来源。');
            $prior=DB::table('umi_v2_capture_approvals')->where('source_sha256',$sha)->first();
            if ($prior) {
                if ($prior->reason!==trim($reason) || (int)$prior->actor_id!==$actor
                    || !CarbonImmutable::parse($prior->cutoff_at,config('app.timezone'))->equalTo(CarbonImmutable::parse($cutoff)))
                    throw new DomainException('该来源已经按其他复核信息核定。');
                return $prior;
            }
            $approvalId=DB::table('umi_v2_capture_approvals')->insertGetId(['batch_id'=>$id,'source_sha256'=>$sha,
                'cutoff_at'=>FundedTime::database(CarbonImmutable::parse($cutoff)),'reason'=>trim($reason),
                'actor_id'=>$actor,'created_at'=>FundedTime::database(now())]);
            return DB::table('umi_v2_capture_approvals')->find($approvalId);
        },3);
    }
}
