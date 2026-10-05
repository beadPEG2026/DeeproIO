<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Models\{User\User,Wallet\Wallet,Currency\Currency};
use App\Services\Wallet\InternalTransferRecipient;
use Illuminate\Support\Facades\{DB,Event,Queue,Http};
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

final class UidInternalTransferTest extends TestCase
{
    private User $sender;
    private User $recipient;
    protected function setUp(): void
    {
        parent::setUp();self::assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();config(['cache.default'=>'array','broadcasting.default'=>'log']);Event::fake();Queue::fake();Http::preventStrayRequests();
        $make=fn()=>User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'kyc_verified_at'=>now(),'deleted'=>false,'deactivated'=>false,'is_xn'=>false,'is_xm'=>false,'vip'=>0,'referral_id'=>null,'referral_code'=>'QA'.bin2hex(random_bytes(8))]));
        $this->sender=$make();$this->recipient=$make();Sanctum::actingAs($this->sender,['withdraw']);$this->actingAs($this->sender);
    }
    protected function tearDown(): void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    public function test_uid_and_numeric_legacy_code_cannot_redirect_each_other(): void
    {
        $uid=InternalTransferRecipient::uid($this->recipient->id);$s=app(InternalTransferRecipient::class);
        $this->sender->referral_code=$uid;$this->sender->save();
        self::assertSame($this->recipient->id,$s->resolve($this->sender,$uid,'uid')->id);
        self::assertSame($this->recipient->id,$s->resolve($this->sender,(string)$this->recipient->id,'uid')->id);
        self::assertSame($this->recipient->id,$s->resolve($this->sender,$this->recipient->referral_code,'legacy_code')->id);
        $this->expectException(ValidationException::class);$s->resolve($this->sender,$uid,'legacy_code');
    }
    public function test_invalid_self_and_unavailable_recipient_fail_before_money_moves(): void
    {
        $s=app(InternalTransferRecipient::class);
        foreach(['0','-1','1.0','1e2','abc',str_repeat('9',21),InternalTransferRecipient::uid($this->sender->id)] as $id){
            try{$s->resolve($this->sender,$id,'uid');self::fail('Invalid UID accepted');}catch(ValidationException){}
        }
        foreach(['deactivated','deleted','is_xn','is_xm'] as $field){$this->recipient->$field=true;$this->recipient->save();try{$s->resolve($this->sender,(string)$this->recipient->id,'uid');self::fail('Unavailable account accepted');}catch(ValidationException){}$this->recipient->$field=false;$this->recipient->save();}
    }
    public function test_real_endpoint_uses_uid_funding_accounts_and_idempotent_receipt(): void
    {
        $c=Currency::whereSymbol('USDT')->firstOrFail();$c->withdraw_status=true;$c->min_withdraw='0';$c->save();
        \Setting::set('wallet.internal_withdraw_min_vip',0);
        $wallets=[];foreach([$this->sender,$this->recipient] as $i=>$user)$wallets[]=Wallet::factory()->create(['user_id'=>$user->id,'currency_id'=>$c->id,'balance_in_wallet'=>$i?'0':'20.123456789123456789','balance_in_trade'=>'0','balance_in_order'=>'0','balance_in_withdraw'=>'0']);
        $payload=['withdraw_type'=>'internal','internal_transfer'=>true,'recipient_type'=>'uid','internal_uid'=>InternalTransferRecipient::uid($this->recipient->id),'symbol'=>'USDT','amount'=>'3.123456789123456789'];
        $first=$this->withHeader('Idempotency-Key','uid-transfer-fixture')->postJson('/api/v1/wallets/withdraw',$payload);self::assertSame(200,$first->status(),$first->getContent());
        self::assertSame(0,bccomp($wallets[0]->fresh()->balance_in_wallet,'17',18));self::assertSame(0,bccomp($wallets[1]->fresh()->balance_in_wallet,$payload['amount'],18));
        $again=$this->withHeader('Idempotency-Key','uid-transfer-fixture')->postJson('/api/v1/wallets/withdraw',$payload)->assertOk();self::assertSame($first->getContent(),$again->getContent());
        self::assertSame(1,DB::table('withdrawals')->where('user_id',$this->sender->id)->count());self::assertSame(1,DB::table('deposits')->where('user_id',$this->recipient->id)->count());
        self::assertSame('UID:'.$payload['internal_uid'],DB::table('withdrawals')->where('user_id',$this->sender->id)->value('address'));
    }
}
