<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Models\Umi\LegacyAccount;
use App\Services\Umi\LegacyShadow;
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Foundation\Testing\DatabaseTransactions;
class UmiShadowTest extends TestCase {
    use DatabaseTransactions;
    public function test_snapshot_inversion_and_tree_formulas_do_not_issue_funds():void {
        $this->assertSame('umi_regression',DB::connection()->getDatabaseName());$before=DB::table('umi_business_rewards')->count();
        foreach([990001,990002,990003] as $id){
            $root=$id===990001;$identity=['id'=>$id,'uuid'=>'shadow-'.$id,'level'=>$root?'V1':'','status'=>1];
            $p=['id'=>$id,'uuid'=>$identity['uuid'],'inviter'=>['user_id'=>$root?999999:$id-($id-990001)],'performance'=>['current_level'=>$identity['level'],'personal_performance'=>$root?'100':'1000','team_performance'=>$root?'2000':'0','small_area_performance'=>$root?'1000':'0'],'quota_summary'=>['total_quota'=>$root?'300':'3000','used_quota'=>'10','today_released'=>$root?'2.5':'8.01']];
            LegacyAccount::create(['legacy_id'=>$id,'legacy_uuid'=>$identity['uuid'],'parent_legacy_id'=>$p['inviter']['user_id'],'batch_id'=>999999,'source_hash'=>hash('sha256',json_encode([$identity,$p],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)),'level'=>$identity['level'],'legacy_status'=>1,'identity'=>$identity,'profile'=>$p]);
            $b=['status'=>1,'burn_type'=>1,'burn_amount'=>$root?'100':'1000','burn_value_usdt'=>$root?'100':'1000'];$raw=json_encode($b);
            DB::table('umi_legacy_records')->insert(['batch_id'=>999999,'source'=>'burn__records','source_id'=>'shadow-'.$id,'legacy_id'=>$id,'payload'=>Crypt::encryptString($raw),'source_hash'=>hash('sha256',$raw)]);
            $raw=json_encode(['当前待释放理财收益(UMI)'=>$root?'0.8':'8','[核查]复投宝本金(UMI)'=>$root?'102.5':'18.01']);DB::table('umi_legacy_summaries')->insert(['legacy_id'=>$id,'payload'=>Crypt::encryptString($raw),'source_hash'=>hash('sha256',$raw)]);
        }
        $r=app(LegacyShadow::class)->report();$this->assertSame(3,$r['accounts']);$this->assertSame([],$r['errors']);foreach($r['matched'] as $k=>$count)$this->assertSame(3,$count,$k);
        $this->assertSame(0,bccomp('1.6',$r['team_total'],12));$this->assertSame(0,bccomp('16.8',$r['linear_total'],12));$this->assertSame($before,DB::table('umi_business_rewards')->count());$this->assertFalse($r['credited']);
    }
}
