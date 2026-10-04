<?php

namespace App\Models\Deposit;

use Illuminate\Database\Eloquent\Model;
final class DepositChannel extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['decimals' => 'integer', 'confirmations' => 'integer', 'start_block' => 'integer', 'reviewed_at' => 'datetime', 'pilot_user_ids' => 'array', 'pilot_started_at' => 'datetime', 'pilot_expires_at' => 'datetime', 'pilot_start_block' => 'integer'];
    public function isPilot(): bool
    {
        return in_array($this->state, ['pilot', 'tested'], true);
    }
    public function pilotUsers(): array
    {
        $ids = array_values(array_unique(array_map('intval', $this->pilot_user_ids ?? [])));
        sort($ids);
        return $ids;
    }
    public function pilotDigest(): string
    {
        return hash('sha256', json_encode([$this->digest(), $this->pilotUsers(), bcadd((string) ($this->pilot_minimum ?? 0), '0', 18), bcadd((string) ($this->pilot_limit ?? 0), '0', 18), $this->pilot_started_at?->toIso8601String(), $this->pilot_expires_at?->toIso8601String(), $this->pilot_start_block]));
    }
    public function scanScope(): string
    {
        return 'channel:' . $this->id . ($this->isPilot() ? ':pilot:' . $this->pilot_digest : '');
    }
    public function hasPilotEvidence(): bool
    {
        if (!$this->pilot_digest || !hash_equals($this->pilot_digest, $this->pilotDigest())) return false;
        return \Illuminate\Support\Facades\DB::table('chain_deposit_receipts')
            ->where('channel_id', $this->id)->where('credited_amount', '>', 0)
            ->where('evidence->config_digest', $this->digest())
            ->where('evidence->pilot_digest', $this->pilot_digest)->exists();
    }
    public function currency()
    {
        return $this->belongsTo(\App\Models\Currency\Currency::class);
    }
    public function network()
    {
        return $this->belongsTo(\App\Models\Network\Network::class);
    }
    public function digest(): string
    {
        $keys = ['currency_id', 'network_id', 'chain', 'kind', 'contract', 'decimals', 'confirmations', 'minimum', 'fee_fixed', 'fee_percent', 'start_block'];
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = (string) $this->{$key};
        }
        return hash('sha256', json_encode($data));
    }
}
