<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ComplianceController extends Controller
{
    public function index()
    {
        // Render the Compliance Audit dashboard page
        return Inertia::render('Admin/Reports/ComplianceAudit', [
            'filters' => request()->all(['period']),
        ]);
    }

    public function exportUsers(Request $request)
    {
        // Optional date period filter
        $period = $request->get('period', []);
        $start = null; $end = null;
        if (is_array($period)) {
            if (!empty($period[0])) { $start = $period[0]; if (strlen($start) <= 10) { $start .= ' 00:00:01'; } }
            if (!empty($period[1])) { $end = $period[1]; if (strlen($end) <= 10) { $end .= ' 23:59:59'; } }
        }

        $query = User::query()->with('roles');
        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // Header columns commonly required by regulators
            fputcsv($out, [
                'User ID', 'Email', 'Nickname', 'Name', 'Email Verified', 'KYC Verified', 'Bank Verified', 'Merchant Verified', 'Roles', 'Created At', 'Last Seen At',
            ]);

            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $u) {
                    $roles = implode(',', array_column($u->roles->toArray(), 'name'));
                    fputcsv($out, [
                        $u->id,
                        $u->email,
                        $u->nickname,
                        $u->name,
                        $u->email_verified_at ? '1' : '0',
                        $u->kyc_verified_at ? '1' : '0',
                        property_exists($u, 'bank_verified') ? ($u->bank_verified ? '1' : '0') : '0',
                        property_exists($u, 'merchant_verified_at') ? ($u->merchant_verified_at ? '1' : '0') : '0',
                        $roles,
                        $u->created_at,
                        $u->last_seen_at,
                    ]);
                }
            });

            fclose($out);
        }, 'admin_users_compliance_export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
