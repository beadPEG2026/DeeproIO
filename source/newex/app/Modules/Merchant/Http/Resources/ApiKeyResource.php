<?php

namespace App\Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiKeyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'public_key' => $this->public_key,
            'key_prefix' => $this->key_prefix,
            'secret_key_last4' => $this->secret_key_last4,
            'environment' => $this->environment,
            'is_active' => $this->is_active,
            'permissions' => $this->permissions,
            'ip_whitelist' => $this->ip_whitelist,

            // Rate limits
            'rate_limit_per_minute' => $this->rate_limit_per_minute,
            'rate_limit_per_hour' => $this->rate_limit_per_hour,

            // Usage stats
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_used_ip' => $this->last_used_ip,
            'total_requests' => $this->total_requests,

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
