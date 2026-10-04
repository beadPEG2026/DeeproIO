<?php
namespace App\Console\Commands\Umi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\Umi\LegacyAccount;
class VerifyLegacy extends Command {
    protected $signature='umi:verify';
    protected $description='只读校验 UMI 原始文件、记录及身份快照，不输出个人联系方式';
    public function handle():int {
        $r=['files'=>0,'records'=>0,'accounts'=>0,'file_hash_errors'=>0,'record_hash_errors'=>0,'account_hash_errors'=>0,'external_parent_count'=>0,'activated'=>0,'approved_contacts'=>0];
        foreach(DB::table('umi_source_files')->orderBy('id')->cursor() as $f){$r['files']++;if(!hash_equals($f->sha256,hash('sha256',Crypt::decryptString($f->contents))))$r['file_hash_errors']++;}
        foreach(DB::table('umi_legacy_records')->orderBy('id')->cursor() as $v){$r['records']++;if(!hash_equals($v->source_hash,hash('sha256',Crypt::decryptString($v->payload))))$r['record_hash_errors']++;}
        $outside=[];$r['account_projection_errors']=0;
        foreach(LegacyAccount::cursor() as $a){$r['accounts']++;if(!hash_equals($a->source_hash,hash('sha256',json_encode([$a->identity,$a->profile],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION))))$r['account_hash_errors']++;
            if(array_diff(app(\App\Services\Umi\LegacyIntegrity::class)->account($a),['source_hash']))$r['account_projection_errors']++;
            if($a->parent_legacy_id && !LegacyAccount::whereKey($a->parent_legacy_id)->exists())$outside[$a->parent_legacy_id]=true;
            if($a->user_id)$r['activated']++;if($a->approved_email)$r['approved_contacts']++;
        }
        $r['summary_users']=0;$r['summary_hash_errors']=0;
        foreach(DB::table('umi_legacy_summaries')->cursor() as $s){$r['summary_users']++;if(!hash_equals($s->source_hash,hash('sha256',Crypt::decryptString($s->payload))))$r['summary_hash_errors']++;}
        $r['external_parent_count']=count($outside);$this->line(json_encode($r,JSON_PRETTY_PRINT));
        return $r['file_hash_errors']+$r['record_hash_errors']+$r['account_hash_errors']+$r['summary_hash_errors']+$r['account_projection_errors']===0?self::SUCCESS:self::FAILURE;
    }
}
