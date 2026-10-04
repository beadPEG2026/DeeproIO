<?php
namespace App\Services\Operations;

use App\Models\Staking\Staking;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Changes product configuration only. Existing position snapshots are never edited. */
final class StakingConfiguration
{
    public const FIELDS=['currency_id','allowed_days','rewards_percentage','min_amount','max_amount','status','staking_type','rewards_percentage_a','Introduction','rewards_percentage_t','currency_idd'];
    private function key(int $id):string { return 'staking.configuration.'.$id; }
    public function controls(int $id):array {return json_decode(DB::table('settings')->where('key',$this->key($id))->value('value')??'{}',true)?:[];}
    private function snapshot(Staking $p):array {return $p->only(self::FIELDS);}
    public function revision(Staking $p):string {return hash('sha256',json_encode([$this->snapshot($p),$this->controls($p->id)]));}
    private function put(int $id,array $value):void {DB::table('settings')->updateOrInsert(['key'=>$this->key($id)],['value'=>json_encode($value,JSON_THROW_ON_ERROR)]);}
    public function validateRates(array $data,?float $limit,bool $ack):void {
        $days=explode(',',$data['allowed_days']);$rates=explode(',',$data['rewards_percentage']);
        foreach($days as $i=>$d){
            $apr=(float)$rates[$i]*365/(int)$d;
            if(!is_finite($apr)||($limit!==null&&$apr>$limit+0.000001))throw ValidationException::withMessages(['rewards_percentage'=>__('APR exceeds the configured limit.')]);
            if($apr>100&&!$ack)throw ValidationException::withMessages(['high_apr_ack'=>__('Confirm the APR above 100% before saving.')]);
        }
    }
    public function save(?Staking $product,array $data,array $control,int $actor):Staking {
        return DB::transaction(function()use($product,$data,$control,$actor){
            $before=null;$state=[];
            if($product){$product=Staking::whereKey($product->id)->lockForUpdate()->firstOrFail();$before=$this->snapshot($product);$state=$this->controls($product->id);
                if(!hash_equals($this->revision($product),(string)($control['revision']??'')))throw ValidationException::withMessages(['revision'=>__('Configuration changed. Reload before saving.')]);}
            $funded=[];
            if (!empty($state['funded_term'])) {
                if ((int)$data['currency_id']!==(int)$product->currency_id) throw ValidationException::withMessages(['currency_id'=>__('Funded product currency cannot be changed.')]);
                $uid=array_key_exists('reward_user_id',$control)?($control['reward_user_id']?(int)$control['reward_user_id']:null):($state['reward_user_id']??null);
                $pool=(string)($control['pool_limit']??$state['pool_limit']??'0');
                if (!is_numeric($pool) || bccomp($pool,(string)$data['max_amount'],18)<0) throw ValidationException::withMessages(['pool_limit'=>__('Product cap must cover the maximum subscription.')]);
                if ($uid && ($state['reward_funding']??'reserved')!=='platform') { $u=\App\Models\User\User::find($uid); if (!$u || $u->deleted || $u->deactivated || $u->is_xn) throw ValidationException::withMessages(['reward_user_id'=>__('Select an active real account.')]); }
                $funded=['funded_term'=>true,'reward_funding'=>$state['reward_funding']??'reserved','reward_user_id'=>$uid,'pool_limit'=>$pool];
                if (!empty($control['effective_at']) && ($uid!==($state['reward_user_id']??null) || $pool!==($state['pool_limit']??'0'))) throw ValidationException::withMessages(['effective_at'=>__('Save funding changes immediately before scheduling product terms.')]);
            }
            $limit=isset($control['apr_limit'])?(float)$control['apr_limit']:null;
            $this->validateRates($data,$limit,(bool)($control['high_apr_ack']??false));
            $due=$control['effective_at']??null;
            if($due){
                if(!$product)throw ValidationException::withMessages(['effective_at'=>__('Create the product before scheduling a change.')]);
                $state=$funded+['apr_limit'=>$limit,'pending'=>['data'=>$data,'effective_at'=>\Carbon\Carbon::parse($due)->utc()->toIso8601String(),'base'=>$before,'actor'=>$actor,'reason'=>$control['reason']]];
                $this->put($product->id,$state);
                History::append('staking_config',$product->id,'schedule',['before'=>$before,'after'=>$data,'effective_at'=>$state['pending']['effective_at'],'apr_limit'=>$limit],$actor,$control['reason']);
            }else{
                if($product)$product->update($data);else $product=Staking::create($data);
                $this->put($product->id,$funded+['apr_limit'=>$limit]);
                History::append('staking_config',$product->id,$before?'update':'create',['before'=>$before,'after'=>$this->snapshot($product->fresh()),'replaced_pending'=>!empty($state['pending']),'apr_limit'=>$limit,'funding_before'=>array_intersect_key($state,$funded),'funding_after'=>$funded],$actor,$control['reason']);
            }
            return $product;
        });
    }
    public function applyDue():int {
        $count=0;
        foreach(DB::table('settings')->where('key','like','staking.configuration.%')->get(['key','value']) as $row){
            $id=(int)substr($row->key,strlen('staking.configuration.'));
            $count+=DB::transaction(function()use($id){
                $p=Staking::whereKey($id)->lockForUpdate()->first();$state=$this->controls($id);$pending=$state['pending']??null;
                if(!$pending||\Carbon\Carbon::parse($pending['effective_at'])->isFuture())return 0;
                unset($state['pending']);
                $actor=\App\Models\User\User::find($pending['actor']);
                if(!$p||(int)$p->staking_type!==0||$this->snapshot($p)!==$pending['base']||!$actor||$actor->deleted||$actor->deactivated||!$actor->hasAnyRole(['superadmin','perm_stakings'])){
                    $state['last_result']='cancelled';$this->put($id,$state);History::append('staking_config',$id,'schedule_cancelled',['effective_at'=>$pending['effective_at']],null,'Product or operator authorization changed');return 0;
                }
                $this->validateRates($pending['data'],$state['apr_limit']??null,true);
                $p->update($pending['data']);$state['last_result']='applied';$this->put($id,$state);
                History::append('staking_config',$id,'scheduled_update',['before'=>$pending['base'],'after'=>$this->snapshot($p->fresh())],$pending['actor'],$pending['reason']);return 1;
            });
        }
        return $count;
    }
}
