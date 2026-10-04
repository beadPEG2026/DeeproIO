<?php
namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\FundTransferReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class AdminControlsController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => 'nullable|in:pending,completed,rejected']);
        $query = DB::table('admin_fund_transfers')->orderByDesc('created_at');
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        return Inertia::render('Admin/Controls/Index', [
            'transfers' => $query->paginate(30)->withQueryString(), 'filters' => $filters,
            'checks' => [
                'pending_transfers' => DB::table('admin_fund_transfers')->where('status','pending')->count(),
                'legacy_option_sources' => DB::table('options')->whereIn('status',['scheduled','active'])->whereNull('funding_domain')->count(),
                'orphan_roles' => DB::table('model_has_roles as mr')->leftJoin('users as u','u.id','=','mr.model_id')
                    ->where('mr.model_type', \App\Models\User\User::class)->whereNull('u.id')->count(),
            ],
        ]);
    }

    public function review(Request $request, string $id, FundTransferReview $service)
    {
        $data = $request->validate(['action' => 'required|in:approve,reject', 'reason' => 'required|string|min:10|max:1000']);
        $service->review($request->user(), $id, $data['action'] === 'approve', $data['reason']);
        return back()->with('success', __('Review recorded.'));
    }
}
