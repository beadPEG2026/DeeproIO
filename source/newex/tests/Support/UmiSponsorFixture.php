<?php
namespace Tests\Support;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
trait UmiSponsorFixture
{
    /** Models an existing activated sponsor; new children still use the production invite resolver. */
    private function umiSponsorCode(): string
    {
        $this->assertSame('umi_regression', DB::connection()->getDatabaseName());
        $user=User::withoutEvents(fn()=>User::factory()->create(['email'=>'sponsor-'.Str::uuid().'@example.invalid','email_verified_at'=>now(),'deleted'=>false,'deactivated'=>false]));
        $code='U'.strtoupper(Str::random(12));
        DB::table('umi_business_accounts')->insert(['user_id'=>$user->id,'code'=>$code,'fixture'=>false,'created_at'=>now(),'updated_at'=>now()]);
        return $code;
    }
}
