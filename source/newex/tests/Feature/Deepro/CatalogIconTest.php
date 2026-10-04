<?php
namespace Tests\Feature\Deepro;
use App\Models\Currency\Currency;
use App\Services\Market\CatalogIcon;
use Tests\TestCase;
final class CatalogIconTest extends TestCase {
 public function test_every_catalog_icon_has_verified_bytes_and_matching_asset_identity():void {
  $fields=['ETH'=>'contract','BSC'=>'bep_contract','MATIC'=>'matic_contract','SOL'=>'sol_contract','TRX'=>'trc_contract'];
  foreach(config('catalog-icons') as $symbol=>$icon){
   $this->assertSame($icon['sha256'],hash_file('sha256',public_path($icon['path'])),$symbol);
   if(isset($icon['securityCode'])){
    $c=new Currency();$c->forceFill(['symbol'=>$symbol,'asset_category'=>'stock','asset_reference'=>config('hk-price-products.assets.'.$symbol)]);
    $this->assertSame($icon['path'],CatalogIcon::path($c),$symbol);
    $ref=$c->asset_reference;$ref['securityCode']='00000';$c->asset_reference=$ref;$this->assertNull(CatalogIcon::path($c));
   }else foreach($icon['identities'] as $identity){
    $c=new Currency();$c->forceFill(['symbol'=>$symbol,'is_token'=>!$identity['native']]);
    if(!$identity['native'])$c->{$fields[$identity['chain']]}=$identity['contract'];
    $this->assertSame($icon['path'],CatalogIcon::path($c),$symbol);
    if(!$identity['native']){$c->{$fields[$identity['chain']]}='wrong-contract';$this->assertNull(CatalogIcon::path($c));}
   }
  }
 }
}
