<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Umi\V2\LocalEngine;
use DomainException;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class UmiV2AdminController extends Controller
{
    public function index(Request $request, LocalEngine $engine)
    {
        if (config('umi-v2.funded_enabled') || \App\Services\Umi\V2\FundedRuntime::schemaReady()) {
            return redirect()->route('admin.umi.operations');
        }
        $engine->assertEnabled();
        return Inertia::render('Umi/V2Console', ['state' => $engine->adminDashboard()])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request, LocalEngine $engine)
    {
        $engine->assertEnabled();
        $input = $request->validate([
            'action' => 'required|in:fund,inventory,inbound,day,batch,topup,level,chain_settings',
            'request_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'member_id' => 'required_if:action,fund,level,inbound|nullable|integer|min:1',
            'withdrawal_id' => 'required_if:action,topup|nullable|integer|min:1',
            'amount' => ['required_if:action,fund,inventory,inbound', 'nullable', 'string', 'max:60',
                'regex:/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,24})?$/'],
            'source' => 'required_if:action,inbound|nullable|in:exchange,chain',
            'source_ref' => ['required_if:action,inbound', 'nullable', 'string', 'max:80',
                'regex:/^[A-Za-z0-9:_-]+$/'],
            'business_date' => 'required_if:action,day|nullable|date_format:Y-m-d',
            'static_rate' => ['required_if:action,day', 'nullable', 'string', 'max:60',
                'regex:/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,24})?$/'],
            'level' => 'required_if:action,level|nullable|integer|between:0,9',
            'token_contract' => 'nullable|string|max:42',
            'burn_address' => 'nullable|string|max:42',
            'dedicated_address' => 'nullable|string|max:42',
        ]);
        $actorId = (int) $request->user()->id;
        try {
            $result = match ($input['action']) {
                'fund' => $engine->fundTestWallet((int) $input['member_id'],
                    $input['amount'], $input['request_key'], $actorId),
                'inventory' => $engine->fundTestRewardInventory($input['amount'],
                    $input['request_key'], $actorId),
                'inbound' => $engine->recordSandboxInbound((int) $input['member_id'],
                    $input['source'], $input['source_ref'], $input['amount'], $actorId),
                'day' => $engine->runDay($input['business_date'], $input['static_rate'],
                    $input['request_key'], $actorId),
                'batch' => $engine->confirmSandboxBatch($input['request_key'], $actorId),
                'topup' => $engine->simulateExternalTopup((int) $input['withdrawal_id'],
                    $input['request_key'], $actorId),
                'level' => $engine->setTestLevel((int) $input['member_id'], (int) $input['level']),
                'chain_settings' => $engine->saveChainSettings(
                    $input['token_contract'] ?? null, $input['burn_address'] ?? null,
                    $input['dedicated_address'] ?? null, $input['request_key'], $actorId),
            };
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422)
                ->header('Cache-Control', 'no-store');
        }
        return response()->json(['ok' => true, 'result' => $result,
            'state' => $engine->adminDashboard()])
            ->header('Cache-Control', 'private, no-store');
    }
}
