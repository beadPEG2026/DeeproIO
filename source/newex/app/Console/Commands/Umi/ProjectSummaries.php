<?php
namespace App\Console\Commands\Umi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\Umi\LegacyAccount;
class ProjectSummaries extends Command {
    protected $signature='umi:project-summaries';
    protected $description='从已封存的 CSV 核对并提取 UMI 宝与资产构成，不增加账户余额';
    public function handle():int {
        $rows=[];
        foreach(DB::table('umi_source_files')->where('path','like','%/umbrella.csv')->orderBy('id')->cursor() as $f){
            $stream=fopen('php://temp','r+');fwrite($stream,Crypt::decryptString($f->contents));rewind($stream);
            $header=fgetcsv($stream,0,',','"','');if(!$header){fclose($stream);continue;}$header[0]=ltrim($header[0],"\xEF\xBB\xBF");
            while(($values=fgetcsv($stream,0,',','"',''))!==false){
                if(count($values)===1 && $values[0]===null)continue;
                if(count($values)!==count($header))throw new \RuntimeException('CSV 列数不匹配。');
                $r=array_combine($header,$values);$id=$r['用户ID']??null;if(!$id || !ctype_digit($id))throw new \RuntimeException('CSV 用户 ID 无效。');
                if(isset($rows[$id]) && $rows[$id]!==$r)throw new \RuntimeException('重复 CSV 视图存在差异，已停止提取。');$rows[$id]=$r;
            }fclose($stream);
        }
        DB::transaction(function()use($rows){foreach($rows as $id=>$r){
            if(!LegacyAccount::whereKey($id)->exists())throw new \RuntimeException('CSV 用户不在已导入范围内。');
            $raw=json_encode($r,JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$raw);$old=DB::table('umi_legacy_summaries')->where('legacy_id',$id)->first();
            if($old && $old->source_hash!==$hash)throw new \RuntimeException('已保存汇总与原始 CSV 不一致，未覆盖。');
            if(!$old)DB::table('umi_legacy_summaries')->insert(['legacy_id'=>$id,'payload'=>Crypt::encryptString($raw),'source_hash'=>$hash]);
        }});
        $this->line(json_encode(['summary_users'=>count($rows),'accounts_without_csv'=>LegacyAccount::count()-count($rows),'funds_credited'=>false]));return self::SUCCESS;
    }
}
