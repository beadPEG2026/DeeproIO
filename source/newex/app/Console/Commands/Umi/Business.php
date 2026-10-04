<?php
namespace App\Console\Commands\Umi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;
use App\Models\User\User;
use App\Services\Umi\Business\{Engine,Ledger,Rules,Settlement};
final class Business extends Command {
    protected $signature='umi:business {operation : seed, audit, shadow, settle} {--day= : Explicit next local business date}';
    protected $description='UMI 本地独立业务验收；不写入原历史权益或交易所钱包';
    public function handle(Engine $engine):int {
        $engine->rules->assertLocal();$operation=$this->argument('operation');
        if($operation==='audit'){$r=DB::transaction(function()use($engine){DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();return $engine->ledger->audit();});$this->line(json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));return $r['ok']?0:1;}
        if($operation==='shadow'){$r=app(\App\Services\Umi\LegacyShadow::class)->report();$this->line(json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));return count($r['errors'])?1:0;}
        if(config('umi-v2.funded_enabled')){$this->error('账户已统一，原业务写入已停用。');return self::FAILURE;}
        if($operation==='settle'){$this->line(json_encode(app(Settlement::class)->advance(0,(string)$this->option('day'),'cli-day-'.(string)$this->option('day'))));return 0;}
        if($operation!=='seed'){$this->error('无效操作');return 1;}
        $path=storage_path('app/private/umi-business-acceptance.json');
        if(is_file($path)){$this->info('专用验收账号已创建，未重复注资。');return 0;}
        $credentials=DB::transaction(function()use($engine){
            DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();$engine->rules->ensure();$accounts=[];$credentials=[];
            $fixtures=[['root',null,'1000'],['branch-a','root','3000'],['branch-b','root','1000'],['leaf-a','branch-a','1000']];
            foreach($fixtures as [$name,$parent,$purchase]){
                $email='umi-'.$name.'@deepro.test';if(User::where('email',$email)->exists())throw new \RuntimeException('Fixture account exists; restore private acceptance manifest instead of overwriting.');
                $password=Str::random(30).'9!a';$u=User::create(['name'=>'UMI 验收 '. $name,'email'=>$email,'password'=>Hash::make($password),'email_verified_at'=>now(),'withdrawal_disabled'=>true]);$u->assignRole('user');
                $engine->enroll($u->id,$parent?$accounts[$parent]->code:null,'acceptance-enroll-'.$name,true);$a=$engine->owned($u->id);$accounts[$name]=$a;
                $engine->fund($a->id,'20000','UMI','acceptance-fund-umi-'.$name);$engine->fund($a->id,'10000','USDT','acceptance-fund-usdt-'.$name);$engine->purchase($a->id,$u->id,$purchase,'acceptance-purchase-'.$name);
                $credentials[$name]=['email'=>$email,'password'=>$password,'user_id'=>$u->id,'account_id'=>$a->id,'code'=>$a->code];
            }
            $email='umi-admin@deepro.test';if(User::where('email',$email)->exists())throw new \RuntimeException('Fixture admin exists; no overwrite');
            $password=Str::random(30).'9!a';$u=User::create(['name'=>'UMI 本地验收管理员','email'=>$email,'password'=>Hash::make($password),'email_verified_at'=>now(),'withdrawal_disabled'=>true]);$u->assignRole('superadmin');$credentials['admin']=['email'=>$email,'password'=>$password,'user_id'=>$u->id];
            return ['created_at'=>now()->toIso8601String(),'scope'=>'local-only UMI acceptance, SMTP Mailpit, no mainnet assets','accounts'=>$credentials];
        });
        if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,json_encode($credentials,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));chmod($path,0600);
        $this->info('已创建 4 个独立业务账户、1 个本地管理员；登录资料仅保存到私有验收文件。');return 0;
    }
}
