<?php
namespace App\Services\Market;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\Operations\History;

/** Display configuration only. It never changes trading or wallet switches. */
final class MarketPresentation {
    private const KEY='market.discovery.display';
    public function defaults(): array { return ['firstScreen'=>true,'cardOrder'=>['global','altcoin','sentiment'],'defaultList'=>'hot','defaultCategory'=>'crypto','global'=>true,'altcoin'=>true,'sentiment'=>true,'sparklines'=>true,'benchmarks'=>true,'sectors'=>[
        ['name'=>'Layer 1','symbols'=>['BTC','ETH','SOL','BNB','ADA','TRX','DOGE']],
        ['name'=>'PoW','symbols'=>['BTC','DOGE']],
        ['name'=>'Meme','symbols'=>['DOGE']],
    ]]; }
    public function get(): array {
        $raw=DB::table('settings')->where('key',self::KEY)->value('value');
        $saved=is_string($raw)?json_decode($raw,true):null;
        return array_replace($this->defaults(),is_array($saved)?$saved:[]);
    }
    public function validate(array $input): array {
        $input=array_replace($this->defaults(),$input);
        $rules=['firstScreen'=>'required|boolean','defaultList'=>'required|in:hot,gainers,losers,new','cardOrder'=>'required|array|size:3','cardOrder.*'=>'required|in:global,altcoin,sentiment|distinct','defaultCategory'=>'required|in:crypto,stocks,favorites','sectors'=>'present|array|max:12','sectors.*'=>'array:name,symbols','sectors.*.name'=>'required|string|max:24|distinct','sectors.*.symbols'=>'required|array|min:1|max:100','sectors.*.symbols.*'=>'required|string|max:30|regex:/^[A-Za-z0-9]+$/D'];
        foreach(['global','altcoin','sentiment','sparklines','benchmarks'] as $key)$rules[$key]='required|boolean';
        $data=Validator::make($input,$rules)->validate();
        $known=DB::table('currencies')->pluck('symbol')->all();
        foreach($data['sectors'] as &$sector) {
            $sector['symbols']=array_values(array_unique($sector['symbols']));
            if(array_diff($sector['symbols'],$known))throw \Illuminate\Validation\ValidationException::withMessages(['sectors'=>__('Unknown currency symbol')]);
        }
        return $data;
    }
    public function save(array $data,int $actor,string $reason):void {
        DB::transaction(function()use($data,$actor,$reason){
            // Serialize display edits independently of operational settings.
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('market.discovery.display'))");
            $before=$this->get();
            DB::table('settings')->updateOrInsert(['key'=>self::KEY],['value'=>json_encode($data,JSON_THROW_ON_ERROR)]);
            History::append('market_display','global','update',['before'=>$before,'after'=>$data],$actor,$reason);
        });
    }
}
