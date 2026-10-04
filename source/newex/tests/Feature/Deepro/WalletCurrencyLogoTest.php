<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Models\User\User;
use App\Models\Currency\Currency as Model;
use App\Http\Resources\Currency\Currency;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
final class WalletCurrencyLogoTest extends TestCase {
    use DatabaseTransactions;
    public function test_missing_icon_keeps_currency_payload_and_deposit_withdraw_pages_available():void {
        $this->assertSame('umi_regression',DB::connection()->getDatabaseName());
        $a=Model::where('symbol','AAPLon')->firstOrFail();$a->setRelation('file',null);
        $r=(new Currency($a))->toArray(request());$this->assertSame('/images/currencies/aaplon.png',$r['logo']);$this->assertStringEndsWith('/images/currencies/aaplon.png',$r['full_logo_path']);
        $unknown=clone $a;$unknown->symbol='ZZZLOGOFIXTURE';$unknown->name='Unknown fixture';$unknown->logo=null;$fallback=(new Currency($unknown))->toArray(request());$this->assertSame('/images/currency-placeholder.svg',$fallback['logo']);
        $a->file_id=null;$a->save();$u=User::factory()->create();$u->assignRole('user');
        $this->actingAs($u)->get('/wallets/deposit/crypto/AAPLon')->assertOk();
        $this->get('/wallets/withdraw/crypto/AAPLon')->assertOk();
        $this->get('/wallets/deposit/crypto/USDT')->assertOk();
        $this->get('/wallets/withdraw/crypto/USDT')->assertOk();
    }
}
