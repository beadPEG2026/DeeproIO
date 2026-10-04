<?php
namespace App\Services\SystemMonitor;

use Carbon\CarbonImmutable;

final class OperationsHealth
{
    private const NAMES=['containers','data_disk','postgres','pitr_evidence','failed_jobs','deposit_scans','deposit_backfill','trx_scans','backup_freshness','backup_restore','queue_wait'];
    private const PRIORITY=['ok'=>0,'unknown'=>1,'warning'=>2,'critical'=>3];

    public function summary(?string $path=null): array
    {
        $empty=['status'=>'unknown','fresh'=>false,'generated_at'=>null,'checks'=>[]];
        $path=$path ?? storage_path('app/operations/health.json');
        if (!is_file($path) || filesize($path)>262144) return $empty;
        try {
            $report=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
            if (($report['schema_version'] ?? null)!==2 || ($report['mode'] ?? null)!=='read_only' || empty($report['generated_at'])) return $empty;
            $time=CarbonImmutable::parse($report['generated_at']);
            $age=$time->diffInSeconds(CarbonImmutable::now(),false);
            if ($age < -60 || $age > 180) return $empty;
            $result=['status'=>'ok','fresh'=>true,'generated_at'=>$time->toISOString(),'checks'=>[]];
            // Only known aggregate checks; raw diagnostic text stays off the page.
            foreach (self::NAMES as $name) {
                $matches=array_values(array_filter($report['checks'] ?? [],fn($c)=>is_array($c) && ($c['name'] ?? null)===$name));
                $check=count($matches)===1?$matches[0]:[];
                $status=array_key_exists($check['status'] ?? '',self::PRIORITY)?$check['status']:'unknown';
                $item=['name'=>$name,'status'=>$status];
                if (in_array($name,['deposit_scans','deposit_backfill'],true)) {
                    $item['chains']=[];
                    foreach (['ethereum','bsc','polygon','xlayer','arbitrum','optimism','avalanche','base','tron','solana'] as $chain) {
                        $row=$check['details'][$chain] ?? null;
                        $countKey=$name==='deposit_backfill'?'pending':'attention';
                        if (!is_array($row) || !$this->validCount($row['scopes'] ?? null) || !$this->validCount($row[$countKey] ?? null) || $row[$countKey]>$row['scopes']) continue;
                        $item['chains'][]=['name'=>$chain,'scopes'=>$row['scopes'],'attention'=>$row[$countKey]];
                    }
                }
                $result['checks'][]=$item;
                if (self::PRIORITY[$status]>self::PRIORITY[$result['status']]) $result['status']=$status;
            }
            return $result;
        } catch (\Throwable $e) { return $empty; }
    }

    private function validCount($value): bool { return is_int($value) && $value>=0 && $value<=100000; }
}
