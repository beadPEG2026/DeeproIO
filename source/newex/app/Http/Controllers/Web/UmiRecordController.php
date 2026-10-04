<?php
namespace App\Http\Controllers\Web;
use App\Http\Controllers\Controller;
use App\Services\Umi\V2\{RecordCenter,TeamOverview,FundedDashboard,FundedRuntime};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema};
use DomainException;

final class UmiRecordController extends Controller
{
    public function member(Request $request) { return $this->records($request,(int)$request->user()->id); }
    public function admin(Request $request) { \App\Support\UmiAdminAccess::authorize($request->user(),'read'); if ($request->input('format') === 'csv') \App\Support\UmiAdminAccess::authorize($request->user(),'export'); return $this->records($request,null); }

    private function records(Request $request, ?int $userId)
    {
        $f=$request->validate(['dataset'=>'required|in:'.implode(',',array_keys(RecordCenter::DATASETS)),
            'page'=>'nullable|integer|min:1|max:1000000','per_page'=>'nullable|integer|in:25,50,100',
            'status'=>'nullable|string|max:60','source'=>'nullable|string|max:120',
            'member_id'=>'nullable|integer|min:1','root_member_id'=>'nullable|integer|min:1',
            'withdrawal_id'=>'nullable|integer|min:1',
            'search'=>'nullable|string|max:60','date_from'=>'nullable|date_format:Y-m-d',
            'date_to'=>'nullable|date_format:Y-m-d'.($request->filled('date_from')?'|after_or_equal:date_from':''),'format'=>'nullable|in:csv']);
        try {
            if (!FundedRuntime::schemaReady()) throw new DomainException('UMI 记录服务正在准备中。');
            if (($f['format']??'')==='csv') {
                $export=app(RecordCenter::class)->export($f,$userId);
                return response()->streamDownload(function()use($export) {
                    try {fpassthru($export['stream']);} finally {fclose($export['stream']);}
                },'umi-'.$f['dataset'].'-'.now()->format('Ymd-His').'.csv',[
                    'Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store',
                    'X-UMI-Record-Count'=>(string)$export['count'],'X-UMI-Data-SHA256'=>$export['sha256']]);
            }
            return response()->json(app(RecordCenter::class)->read($f,$userId))->header('Cache-Control','private, no-store');
        } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()],422)->header('Cache-Control','no-store'); }
    }

    public function memberTeam(Request $request)
    {
        $id=DB::table('umi_v2_members')->where('user_id',$request->user()->id)->value('id');
        abort_unless($id,404); return $this->team($request,(int)$id);
    }

    public function adminTeam(Request $request)
    {
        \App\Support\UmiAdminAccess::authorize($request->user(),'read');
        $v=$request->validate(['member_id'=>'required|integer|min:1']); return $this->team($request,(int)$v['member_id']);
    }

    private function team(Request $request,int $id)
    {
        $v=$request->validate(['page'=>'nullable|integer|min:1|max:1000000']);
        try {
            $team=app(TeamOverview::class)->inspect($id); $page=(int)($v['page']??1);
            $team['rows']=array_slice($team['rows'],($page-1)*25,25);
            $team['page']=$page; $team['last_page']=max(1,(int)ceil($team['team_count']/25));
            return response()->json($team)->header('Cache-Control','private, no-store');
        } catch (DomainException $e) {return response()->json(['message'=>$e->getMessage()],422)->header('Cache-Control','no-store');}
    }

    public function overview(Request $request)
    {
        \App\Support\UmiAdminAccess::authorize($request->user(),'read');
        $v=$request->validate(['member_id'=>'required|integer|min:1']);
        $member=DB::table('umi_v2_members')->find($v['member_id']); abort_unless($member,404);
        $legacy=Schema::hasTable('umi_legacy_accounts')?DB::table('umi_legacy_accounts')->where('user_id',$member->user_id)->first():null;
        $business=Schema::hasTable('umi_business_accounts')?DB::table('umi_business_accounts')->where('user_id',$member->user_id)->where('fixture',false)->first():null;
        $batch=app(\App\Services\Umi\V2\CaptureSnapshots::class)->latestId();
        $legacyId=$legacy?->legacy_id??$business?->legacy_id;
        $source=$batch && $legacyId ? DB::table('umi_v2_capture_accounts')->where('batch_id',$batch)->where('legacy_id',$legacyId)->first():null;
        return response()->json(['member'=>app(FundedDashboard::class)->member((int)$member->user_id),
            'legacy_id'=>$legacyId,'business_account_id'=>$business?->id,
            'captured_account'=>$source?['batch_id'=>$batch,'principal_umi'=>(string)$source->principal_umi,
                'balances'=>json_decode($source->balances_json,true),'source'=>json_decode($source->source_json,true)]:null,
            'account_merge'=>DB::table('umi_v2_account_merges')->where('member_id',$member->id)->first()])
            ->header('Cache-Control','private, no-store');
    }
}
