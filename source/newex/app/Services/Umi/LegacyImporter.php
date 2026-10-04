<?php
namespace App\Services\Umi;
use App\Models\Umi\LegacyAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class LegacyImporter {
    public function import(string $root, bool $commit=false): array {
        $root=realpath($root);
        if (!$root || !is_file($root.'/合并数据/users.json')) throw new \RuntimeException(__('导出目录无效。'));
        $files=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || !$file->isFile()) throw new \RuntimeException(__('导出目录必须只包含普通文件。'));
            $path=substr($file->getPathname(),strlen($root)+1);
            $files[$path]=['sha256'=>hash_file('sha256',$file->getPathname()),'path'=>$file->getPathname()];
        }
        ksort($files);
        $fingerprint=hash('sha256',json_encode(array_map(fn($f)=>$f['sha256'],$files)));
        $read=fn($path)=>json_decode(file_get_contents($root.'/'.$path),true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        $users=$read('合并数据/users.json'); $accounts=[]; $uuids=[];
        foreach($users as $u) {
            $id=(int)$u['id']; $p=$read('用户详情/'.$id.'/profile.json')['data'];
            if($id<1 || isset($accounts[$id]) || isset($uuids[$u['uuid']]) || (string)$p['id']!==(string)$id || $p['uuid']!==$u['uuid']) throw new \RuntimeException(__('用户身份存在重复或详情不匹配，已停止导入。'));
            $uuids[$u['uuid']]=true;
            $accounts[$id]=['list'=>$u,'profile'=>$p,'hash'=>hash('sha256',json_encode([$u,$p],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION))];
        }
        $outside=[];
        foreach($accounts as $id=>$a) {
            $seen=[]; $current=$id;
            while(isset($accounts[$current])) {
                if(isset($seen[$current])) throw new \RuntimeException(__('原关系出现循环，已停止导入。'));
                $seen[$current]=true; $parent=$accounts[$current]['profile']['inviter']['user_id']??null;
                if(!$parent) break;
                if(!isset($accounts[$parent])) $outside[(string)$parent]=true;
                $current=$parent;
            }
        }
        $sources=['burn__records','team-umi-flow','team-usdt-flow','usdt__transactions']; $records=[];
        foreach($sources as $source) {
            $seen=[]; $records[$source]=$read('合并数据/'.$source.'.json');
            foreach($records[$source] as $row) {
                if(isset($seen[(string)$row['id']]) || !isset($accounts[$row['user_id']])) throw new \RuntimeException(__('记录重复或所属用户缺失，已停止导入。'));
                $seen[(string)$row['id']]=true;
            }
        }
        $summary=['users'=>count($accounts),'files'=>count($files),'records'=>array_map('count',$records),
            'external_parent_count'=>count($outside),'contact_review_required'=>count($accounts),
            'fingerprint'=>$fingerprint,'scope'=>'已取得的后台可见范围','funds_credited'=>false];
        if(!$commit) return $summary+['mode'=>'dry-run'];
        return DB::transaction(function() use($files,$fingerprint,$accounts,$records,$summary) {
            // Serializes import batches and rejects changed snapshots instead of overwriting entitlements.
            DB::statement("SELECT pg_advisory_xact_lock(8162026)");
            if(DB::table('umi_import_batches')->where('fingerprint',$fingerprint)->exists()) return $summary+['mode'=>'already-imported'];
            foreach($accounts as $id=>$a) {
                $old=LegacyAccount::find($id);
                if($old && $old->source_hash!==$a['hash']) throw new \RuntimeException(__('历史档案发生变化，需要单独对账；未覆盖已有档案。'));
            }
            $batch=DB::table('umi_import_batches')->insertGetId(['fingerprint'=>$fingerprint,'summary'=>json_encode($summary,JSON_UNESCAPED_UNICODE),'created_at'=>now()]);
            foreach($files as $path=>$f) DB::table('umi_source_files')->insert(['batch_id'=>$batch,'path'=>$path,'sha256'=>$f['sha256'],'contents'=>Crypt::encryptString(file_get_contents($f['path']))]);
            foreach($accounts as $id=>$a) {
                if(LegacyAccount::find($id)) continue;
                $u=$a['list'];$p=$a['profile'];
                LegacyAccount::create(['legacy_id'=>$id,'legacy_uuid'=>$u['uuid'],'parent_legacy_id'=>$p['inviter']['user_id']??null,
                    'batch_id'=>$batch,'source_hash'=>$a['hash'],'level'=>$u['level']??null,'legacy_status'=>$u['status'],
                    'identity'=>$u,'profile'=>$p,'email_lookup'=>!empty($u['email'])?LegacyActivation::lookup($u['email']):null]);
            }
            foreach($records as $source=>$rows) foreach($rows as $row) {
                $raw=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION); $hash=hash('sha256',$raw);
                $old=DB::table('umi_legacy_records')->where('source',$source)->where('source_id',(string)$row['id'])->first();
                if($old && $old->source_hash!==$hash) throw new \RuntimeException(__('历史流水发生变化，需要单独对账。'));
                if(!$old) DB::table('umi_legacy_records')->insert(['batch_id'=>$batch,'source'=>$source,'source_id'=>(string)$row['id'],
                    'legacy_id'=>$row['user_id'],'payload'=>Crypt::encryptString($raw),'source_hash'=>$hash]);
            }
            return $summary+['mode'=>'imported','batch_id'=>$batch];
        });
    }
}
