<?php

namespace App\Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookResource extends JsonResource
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
            'invoice_id' => $this->invoice_id,
            'event_type' => $this->event_type,
            'priority' => $this->priority,
            'status' => $this->status,

            // Delivery info
            'webhook_url' => $this->webhook_url,
            'idempotency_key' => $this->idempotency_key,

            // Attempts
            'attempt_count' => $this->attempt_count,
            'max_attempts' => $this->max_attempts,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),

            // Last response
            'last_response_code' => $this->last_response_code,
            'last_response_time_ms' => $this->last_response_time_ms,
            'last_failure_reason' => $this->last_failure_reason,

            // Payload (without sensitive data)
            'payload_hash' => $this->payload_hash,
            'payload' => $this->when($request->get('include_payload'), function () {
                return $this->payload;
            }),

            // Circuit breaker
            'circuit_breaker_active' => $this->circuit_breaker_active,

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),

            // Attempts (when loaded)
            'attempts' => WebhookAttemptResource::collection($this->whenLoaded('attempts')),
        ];
    }
}
