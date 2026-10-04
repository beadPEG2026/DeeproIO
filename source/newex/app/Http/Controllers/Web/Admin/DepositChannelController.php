<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit\DepositChannel;
use App\Models\Currency\Currency;
use App\Services\Deposit\{DepositChannelPolicy, EvmDepositClient, TronGridClient, ChainAmount};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
final class DepositChannelController extends Controller
{
    public function index()
    {
        $channels = DepositChannel::with(['currency', 'network'])->orderBy('network_id')->orderBy('currency_id')->get()->map(function ($c) {
            return array_merge($c->toArray(), ['symbol' => $c->currency?->symbol, 'network_name' => $c->network?->name, 'issue' => app(DepositChannelPolicy::class)->error($c->currency_id, $c->network_id, true, null, true), 'has_pilot_receipt' => $c->hasPilotEvidence(), 'pilot_expired' => $c->pilot_expires_at?->isPast() ?? false, 'scanner' => DB::table('chain_deposit_scan_states')->where('chain', $c->chain)->where('scope', $c->scanScope())->first()]);
        });
        $assets = Currency::where('type', 'coin')->with('networks')->orderBy('symbol')->get()->map(fn($currency) => [
            'id' => $currency->id, 'symbol' => $currency->symbol,
            'network_options' => \App\Services\Wallet\AssetNetworkOptions::forCurrency($currency, array_keys(DepositChannelPolicy::NETWORKS)),
        ]);
        return Inertia::render('Admin/Networks/DepositChannels', ['automation' => app(\App\Services\Custody\AssetAutomation::class)->inventory(), 'channels' => $channels, 'assets' => $assets, 'unrecognized' => DB::table('unrecognized_deposit_events')->orderByDesc('id')->limit(100)->get(['id', 'chain', 'txn', 'event_index', 'contract', 'reason', 'created_at'])]);
    }
    public function drafts(Request $r)
    {
        abort_if(config('app.readonly'),403);
        DB::transaction(function(){foreach(Currency::where('type','coin')->get() as $currency)app(\App\Services\Custody\AssetAutomation::class)->draft($currency);});
        return redirect()->route('admin.deposit-channels');
    }
    public function preflight(DepositChannel $depositChannel)
    {
        try { return response()->json(app(\App\Services\Deposit\DepositChannelReadiness::class)->inspect($depositChannel)); }
        catch (\Throwable $e) {
            return response()->json(['message' => __('Chain identity or token precision verification failed')], 422);
        }
    }
    public function store(Request $r)
    {
        abort_if(config('app.readonly'), 403);
        app(\App\Services\Deposit\DepositChannelConfiguration::class)->save($r->all(), $r->user()->id);
        return redirect()->route('admin.deposit-channels');
    }
}
