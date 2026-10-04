<?php
namespace App\Services\Custody;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only audit projection. Never select signing payloads or credentials. */
final class AuditQuery
{
    public function filters(Request $request): array
    {
        return $request->validate([
            'audit_actor'=>'nullable|integer|min:1', 'audit_transfer'=>'nullable|integer|min:1',
            'audit_action'=>'nullable|string|max:100', 'audit_page'=>'sometimes|integer|min:1|max:100000',
            'audit_start'=>'nullable|date_format:Y-m-d', 'audit_end'=>'nullable|date_format:Y-m-d|after_or_equal:audit_start',
            'audit_chain'=>'nullable|in:bitcoin,ethereum,bnb,polygon,xlayer,tron,solana,ton',
            'audit_currency'=>'nullable|integer|min:1|exists:currencies,id',
        ]);
    }

    public function query(array $filters)
    {
        $grammar=DB::connection()->getQueryGrammar();
        $json=fn(string $path)=>$grammar->wrap('a.detail->'.$path);
        // String joins avoid casting malformed legacy JSON values to integers.
        $rule='COALESCE('.$json('rule_id').', '.$json('rule->id').')';
        $currency='COALESCE(CAST(t.currency_id AS TEXT), '.$json('currency_id').', '.$json('after->currency_id').', '.$json('before->currency_id').', '.$json('rule->currency_id').', CAST(r.currency_id AS TEXT))';
        $network='COALESCE(CAST(t.network_id AS TEXT), '.$json('network_id').', '.$json('after->network_id').', '.$json('before->network_id').', '.$json('rule->network_id').', CAST(r.network_id AS TEXT))';
        $cases='CASE n.slug ';
        foreach(CustodyNetwork::MAP as $slug=>$mapping)$cases.="WHEN '$slug' THEN '$mapping[0]' ";
        $cases.='ELSE NULL END';
        $chain='COALESCE(t.chain, '.$json('chain').', '.$json('after->chain').', '.$json('before->chain').', '.$cases.')';
        $query=DB::table('custody_audits as a')
            ->leftJoin('custody_transfers as t','t.id','=','a.transfer_id')
            ->leftJoin('cold_storage as r',DB::raw('CAST(r.id AS TEXT)'),'=',DB::raw($rule))
            ->leftJoin('currencies as c',DB::raw('CAST(c.id AS TEXT)'),'=',DB::raw($currency))
            ->leftJoin('networks as n',DB::raw('CAST(n.id AS TEXT)'),'=',DB::raw($network))
            ->select('a.id','a.actor_id','a.action','a.transfer_id','a.created_at','a.detail','c.symbol')
            ->selectRaw($chain.' AS chain');
        foreach(['audit_actor'=>'a.actor_id','audit_transfer'=>'a.transfer_id','audit_action'=>'a.action'] as $key=>$column)
            if(isset($filters[$key]) && $filters[$key]!=='')$query->where($column,$filters[$key]);
        if(!empty($filters['audit_start']))$query->where('a.created_at','>=',$filters['audit_start'].' 00:00:00');
        if(!empty($filters['audit_end']))$query->where('a.created_at','<',\Carbon\CarbonImmutable::parse($filters['audit_end'])->addDay()->startOfDay());
        if(!empty($filters['audit_chain']))$query->whereRaw($chain.' = ?',[$filters['audit_chain']]);
        if(!empty($filters['audit_currency']))$query->whereRaw($currency.' = ?',[(string)$filters['audit_currency']]);
        return $query;
    }

    public function redact(object $row): object
    {
        $row->detail=app(AuditView::class)->detail($row->detail);
        return $row;
    }

    public function export(array $filters)
    {
        // Bound the export to records present when requested; new events remain for the next export.
        $lastId=DB::table('custody_audits')->max('id') ?? 0;
        return response()->streamDownload(function()use($filters,$lastId){
            $file=fopen('php://output','w');
            fputcsv($file,['ID','Date (UTC)','Actor ID','Action','Transfer ID','Chain','Asset','Redacted detail']);
            foreach($this->query($filters)->where('a.id','<=',$lastId)->lazyById(500,'a.id','id') as $row){
                $row=$this->redact($row);
                $cells=[$row->id,$row->created_at,$row->actor_id,$row->action,$row->transfer_id,$row->chain,$row->symbol,json_encode($row->detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
                fputcsv($file,array_map(fn($cell)=>self::csvCell($cell),$cells));
            }
            fclose($file);
        },'custody-audit-'.now()->format('Ymd-His').'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store']);
    }

    private static function csvCell($value): string
    {
        $value=(string)$value;
        return preg_match('/^[\s]*[=+@\-]/u',$value) || preg_match('/^[\t\r\n]/',$value) ? "'".$value : $value;
    }
}
