<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemMonitor\SystemMonitorService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Setting;

class SystemMonitorController extends Controller
{
    protected SystemMonitorService $service;

    public function __construct()
    {
        $this->service = new SystemMonitorService();
    }

    /**
     * Display the system monitor dashboard
     */
    public function wallets() { return view('admin.wallet-readiness',['health'=>app(\App\Services\Wallet\WalletReadiness::class)->snapshot()]); }

    public function index()
    {
        return Inertia::render('Admin/SystemMonitor/Index', [
            'services' => $this->service->getStatus(),
            'servicesGrouped' => $this->service->getStatusGrouped(),
            'summary' => $this->service->getSummary(),
            'operationsHealth' => app(\App\Services\SystemMonitor\OperationsHealth::class)->summary(),
            'categories' => $this->service->getCategories(),
            'alertSettings' => $this->service->getAlertSettings(),
            'version' => app(\App\Services\SystemMonitor\ReleaseInfo::class)->version(),
        ]);
    }

    /**
     * Run system monitor test
     */
    public function test()
    {
        $this->service->startTest();

        return response()->json(['success' => true]);
    }

    /**
     * Run system monitor test with alert sending
     */
    public function testWithAlerts()
    {
        $this->service->startTestWithAlerts();

        return response()->json(['success' => true]);
    }

    /**
     * Update websocket status
     */
    public function websocket(Request $request)
    {
        $status = $request->get('status', false);

        $state = "offline";

        if ($status) {
            $state = "online";
        }

        Setting::set('system-monitor.websocket', $state);
        Setting::set('system-monitor.websocket_checked_at', now()->toDateTimeString());

        return response()->json(['success' => true]);
    }

    /**
     * Update alert settings
     */
    public function updateAlertSettings(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'cooldown' => 'required|integer|min:5|max:1440',
        ]);

        $this->service->updateAlertSettings($validated);

        return response()->json(['success' => true]);
    }

    /**
     * Get real-time status (for polling)
     */
    public function status()
    {
        return response()->json([
            'services' => $this->service->getStatus(),
            'servicesGrouped' => $this->service->getStatusGrouped(),
            'summary' => $this->service->getSummary(),
            'operationsHealth' => app(\App\Services\SystemMonitor\OperationsHealth::class)->summary(),
        ]);
    }

    /**
     * Get current test progress
     */
    public function progress()
    {
        return response()->json($this->service->getTestProgress());
    }

    /**
     * Send test alert email
     */
    public function sendTestAlert()
    {
        $adminEmail = Setting::get('notification.admin_email', false);

        if (!$adminEmail) {
            return response()->json([
                'success' => false,
                'message' => 'Admin email not configured in notification settings.'
            ], 400);
        }

        try {
            $services = $this->service->getStatus();
            $summary = $this->service->getSummary();

            \Mail::to($adminEmail)->send(new \App\Mail\SystemMonitor\SystemMonitorAlert(
                array_filter($services, fn($s) => $s['status'] === 'offline'),
                [
                    'total' => $summary['total'],
                    'online' => $summary['online'],
                    'offline' => $summary['offline'],
                    'critical' => $summary['critical_offline'],
                    'maintenance' => $summary['maintenance'],
                ],
                now()->format('Y-m-d H:i:s')
            ));

            return response()->json(['success' => true, 'message' => 'Test alert sent to ' . $adminEmail]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send alert: ' . $e->getMessage()
            ], 500);
        }
    }
}
