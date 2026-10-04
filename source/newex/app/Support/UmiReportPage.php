<?php
namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** Paginate presentation only. Full-source totals, fingerprints and calculations remain unchanged. */
final class UmiReportPage
{
    public function filters(Request $request, string $kind): array
    {
        return $request->validate([
            'search'=>'nullable|string|max:100',
            'result'=>'nullable|in:'.($kind==='continuity'?'ready,review':'matched,review'),
            'rows_page'=>'sometimes|integer|min:1|max:100000',
        ]);
    }

    public function apply(array $report,array $filters,string $kind,Request $request): array
    {
        $search=trim($filters['search']??'');$result=$filters['result']??'';
        $rows=array_values(array_filter($report['rows'],function($row)use($kind,$search,$result){
            $uid=(string)($row[$kind==='continuity'?'legacy_id':'uid']);
            $text=$kind==='continuity'?$uid.' '.implode(' ',$row['issues']):$uid;
            if($search!=='' && !str_contains($text,$search))return false;
            $state=$kind==='continuity'?$row['status']:(!in_array(false,$row['checks'],true)?'matched':'review');
            return $result==='' || $result===$state;
        }));
        $page=(int)($filters['rows_page']??1);
        $report['rows']=new LengthAwarePaginator(array_slice($rows,($page-1)*30,30),count($rows),30,$page,[
            'path'=>$request->url(),'pageName'=>'rows_page','query'=>$request->except('rows_page','format'),
        ]);
        return $report;
    }
}
