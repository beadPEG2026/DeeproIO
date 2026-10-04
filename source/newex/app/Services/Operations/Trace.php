<?php
namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;

/** Project existing records by exact business reference; never expose raw/signing payloads. */
final class Trace
{
    public const TYPES=[
        'deposit'=>['Deposits','admin.reports.deposits'],
        'withdrawal'=>['Withdrawals','admin.reports.withdrawals'],
        'ticket'=>['Support tickets','admin.support.tickets'],
        'channel'=>['Deposit channels','admin.deposit-channels'],
        'custody'=>['Wallet custody','admin.custody'],
        'launchpad'=>['Launchpad','admin.launchpads'],
        'copy_trading'=>['Copy trading','admin.copy-trading'],
        'incident'=>['Operations incidents','admin.operations.incidents'],
        'request'=>['Operation request','admin.operations.trace'],
        'order'=>['Orders','admin.users'],
        'team'=>['Service teams','admin.users'],
        'service'=>['User service overview','admin.operations.users'],
    ];
    public function types():array {
        $out=[];foreach(self::TYPES as $key=>[$label,$route])if(Access::route($route)&&(!in_array($key,['order','team'],true)||auth()->user()->hasRole('superadmin')))$out[]=['value'=>$key,'label'=>$label];return $out;
    }
    public function find(string $type,string $reference):array {
        abort_unless(isset(self::TYPES[$type]),422);Access::requireRoute(self::TYPES[$type][1]);
        if(in_array($type,['order','team'],true))abort_unless(auth()->user()->hasRole('superadmin'),403);
        if($type==='request') {
            if(!\Illuminate\Support\Str::isUuid($reference))return [];
            $event=DB::table('operations_events')->where('request_key',$reference)->first(['object_type','object_id']);
            return $event&&in_array($event->object_type,['incident','service','team'],true)?$this->find($event->object_type,$event->object_id):[];
        }
        if($type==='order') {
            if(!\Illuminate\Support\Str::isUuid($reference))return [];
            $rows=[];foreach(['orders','order_histories'] as $table) {
                $fields=['id','user_id','market_id','side','type','price','initial_quantity','quantity','created_at','updated_at'];if($table==='order_histories')$fields[]='status';
                $r=DB::table($table)->where('id',$reference)->first($fields);if(!$r)continue;
                $r->record_source=$table;$rows[]=$this->result($type,$r,[],[['label'=>'User service overview','url'=>route('admin.operations.user',['user'=>$r->user_id])]]);
            }return $rows;
        }
        if($type==='team') {
            if(!ctype_digit($reference))return []; $r=Teams::listing()->firstWhere('id',(int)$reference);
            return $r?[$this->result($type,$r,History::for('team',$reference),[['label'=>'Service teams','url'=>route('admin.operations.users')]])]:[];
        }
        if(in_array($type,['deposit','withdrawal'],true))return $this->money($type,$reference);
        if($type==='ticket') {
            $row=DB::table('support_messages')->where(function($q)use($reference){$q->where('ticket_id',$reference);if(\Illuminate\Support\Str::isUuid($reference))$q->orWhere('request_key',$reference);if(ctype_digit($reference))$q->orWhere('id',(int)$reference);})
                ->first(['id','ticket_id','user_id','title','status','assigned_to','priority','created_at','updated_at']);
            if(!$row)return [];
            $events=DB::table('support_ticket_entries')->where('support_message_id',$row->id)->orderByDesc('id')->limit(100)->get(['id','actor_id','request_key','kind','body','mail_status','created_at']);
            return [$this->result($type,$row,$events,[['label'=>'Support tickets','url'=>route('admin.support.tickets',['search'=>$row->ticket_id])]])];
        }
        if($type==='service') {
            if(!ctype_digit($reference))return [];
            $u=\App\Models\User\User::find((int)$reference);if(!$u)return [];Access::user($u);
            $case=DB::table('operations_service_cases')->where('user_id',$u->id)->first(['id','user_id','assigned_to','status','priority','due_at','revision','created_at','updated_at']);
            return $case?[$this->result($type,$case,History::for('service',$u->id),[['label'=>'User service overview','url'=>route('admin.operations.user',['user'=>$u->id])]])]:[];
        }
        if(!ctype_digit($reference))return [];
        $id=(int)$reference;
        if($type==='incident') {
            $row=DB::table('operations_incidents')->where('id',$id)->first(['id','check_key','status','severity','assigned_to','due_at','opened_at','resolved_at','revision','updated_at']);
            return $row?[$this->result($type,$row,History::for('incident',$id),[['label'=>'Operations incidents','url'=>route('admin.operations.incidents')]])]:[];
        }
        if($type==='channel') {
            $row=DB::table('deposit_channels')->where('id',$id)->first(['id','currency_id','network_id','chain','kind','state','contract','decimals','start_block','confirmations','minimum','fee_fixed','fee_percent','config_digest','created_at','updated_at']);
            if(!$row)return [];
            $fields=['currency_id','network_id','chain','kind','state','contract','decimals','start_block','confirmations','minimum','fee_fixed','fee_percent','config_digest'];
            $events=DB::table('deposit_channel_audits')->where('channel_id',$id)->orderByDesc('id')->limit(100)->get(['id','actor_id','before','after','created_at'])->map(function($e)use($fields){
                foreach(['before','after'] as $f)$e->$f=array_intersect_key(json_decode($e->$f??'{}',true)??[],array_flip($fields));return $e;
            });
            return [$this->result($type,$row,$events,[['label'=>'Asset workbench','url'=>route('admin.operations.assets',['asset'=>$row->currency_id])]])];
        }
        if($type==='custody') {
            $row=DB::table('custody_transfers')->where('id',$id)->first(['id','purpose','chain','currency_id','network_id','deposit_id','withdrawal_id','rule_id','amount','sent_amount','fee','status','txn','requested_by','approved_by','created_at','completed_at']);
            if(!$row)return [];
            $events=DB::table('custody_audits')->where('transfer_id',$id)->orderByDesc('id')->limit(100)->get(['id','actor_id','action','created_at']);
            $links=[['label'=>'Wallet custody','url'=>route('admin.custody',['audit_transfer'=>$id])]];
            foreach(['deposit','withdrawal'] as $kind)if($row->{$kind.'_id'} && Access::route(self::TYPES[$kind][1]))$links[]=['label'=>self::TYPES[$kind][0],'url'=>route('admin.operations.trace',['type'=>$kind,'reference'=>$row->{$kind.'_id'}])];
            return [$this->result($type,$row,$events,$links)];
        }
        $table=$type==='launchpad'?'launchpads':'copy_trading_traders';
        $row=DB::table($table)->where('id',$id)->first(['id','publication_status','publication_revision','publication_reviewed_by','publication_reviewed_at','created_at','updated_at']);
        if(!$row)return [];
        $events=DB::table('product_publication_events')->where('product_type',$table)->where('product_id',$id)->orderByDesc('id')->limit(100)->get(['id','actor_id','status','reference','content_digest','created_at']);
        return [$this->result($type,$row,$events,[['label'=>self::TYPES[$type][0],'url'=>route(self::TYPES[$type][1])]])];
    }
    private function money(string $type,string $ref):array {
        $q=$type==='deposit'?Records::deposits():Records::withdrawals();$table=$type==='deposit'?'deposits':'withdrawals';$reference=$type.'_id';
        $q->where($table.'.type','coin')->where(function($q)use($ref,$table,$reference){$q->where($table.'.'.$reference,$ref)->orWhere($table.'.txn',$ref);if(ctype_digit($ref))$q->orWhere($table.'.id',(int)$ref);});
        $rows=$q->limit(25)->toBase()->get([$table.'.id',$table.'.'.$reference,$table.'.user_id',$table.'.currency_id',$table.'.network_id',$table.'.txn',$table.'.amount',$table.'.status',$table.'.confirms',$table.'.created_at',$table.'.updated_at']);
        return $rows->map(function($r)use($type,$reference){
            $events=[['action'=>'Record created','created_at'=>$r->created_at],['action'=>'Current business state','status'=>$r->status,'confirmations'=>$r->confirms,'created_at'=>$r->updated_at]];
            $links=[['label'=>self::TYPES[$type][0],'url'=>route(self::TYPES[$type][1],['user_id'=>$r->user_id,'search'=>$r->$reference])]];
            if(Access::route('admin.custody')) {
                foreach(DB::table('custody_transfers')->where($type.'_id',$r->id)->orderByDesc('id')->limit(30)->get(['id','purpose','status','txn','created_at','completed_at']) as $t) {
                    $events[]=['action'=>'Custody task','id'=>$t->id,'purpose'=>$t->purpose,'status'=>$t->status,'txn'=>$t->txn,'created_at'=>$t->created_at,'completed_at'=>$t->completed_at];
                    $links[]=['label'=>'Wallet custody','url'=>route('admin.operations.trace',['type'=>'custody','reference'=>$t->id])];
                }
            }
            $r->asset=DB::table('currencies')->where('id',$r->currency_id)->value('symbol');
            $r->network=DB::table('networks')->where('id',$r->network_id)->value('name');
            return $this->result($type,$r,$events,$links);
        })->all();
    }
    private function result(string $type,$row,$events,array $links):array {
        return ['type'=>$type,'record'=>(array)$row,'events'=>$events,'links'=>$links,'history_limit'=>100];
    }
}
