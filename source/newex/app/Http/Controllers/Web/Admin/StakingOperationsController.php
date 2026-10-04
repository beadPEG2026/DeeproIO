<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use App\Services\Staking\TermOperations;
use App\Services\Operations\History;
use Illuminate\Http\Request;
use Inertia\Inertia;
final class StakingOperationsController extends Controller
{
    private function filters(Request $r): array {return $r->validate(['currency_id'=>'nullable|integer|min:1','user_id'=>'nullable|integer|min:1','status'=>'nullable|in:active,completed,redeemed','failed'=>'nullable|boolean','due_from'=>'nullable|date_format:Y-m-d','due_to'=>'nullable|date_format:Y-m-d'.($r->filled('due_from')?'|after_or_equal:due_from':''),'page'=>'nullable|integer|min:1|max:100000']);}
    public function index(Request $r,TermOperations $service) {return Inertia::render('Admin/Stakings/Operations',['report'=>$service->report($this->filters($r))])->toResponse($r)->header('Cache-Control','private, no-store');}
    public function export(Request $r,TermOperations $service) {
        $filters=$this->filters($r);$stream=fopen('php://temp/maxmemory:2097152','w+');$count=0;
        $service->snapshot(function()use($service,$filters,$stream,&$count){foreach($service->query($filters)->orderBy('id')->cursor() as $s){$row=$service->row($s);if(!$count)fputcsv($stream,array_keys($row),',','"','');fputcsv($stream,array_values($row),',','"','');$count++;}});
        rewind($stream);$hash=hash_init('sha256');hash_update_stream($hash,$stream);$sha=hash_final($hash);rewind($stream);
        History::append('funded_staking_export','all','export',['filters'=>$filters,'count'=>$count,'sha256'=>$sha],$r->user()->id,'Term liability report export');
        return response()->streamDownload(function()use($stream){fpassthru($stream);fclose($stream);},'staking-liabilities.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store','X-Content-SHA256'=>$sha]);
    }
    public function retry(Request $r,TermOperations $service) {
        $v=$r->validate(['stake_id'=>'required|integer|min:1','reason'=>'required|string|min:10|max:240']);
        $s=\App\Models\Staking\StakingUser::findOrFail($v['stake_id']);
        abort_unless(!empty($s->meta['funded_term']) && $s->redemption_date->lte(now()),422);
        try {$settled=$service->settle($s->id,$r->user()->id,$v['reason']);}
        catch(\Throwable $e){report($e);return response()->json(['message'=>__('Settlement failed; inspect the recorded error and retry after reconciliation.')],422);}
        return response()->json(['ok'=>true,'settled'=>$settled]);
    }
}
