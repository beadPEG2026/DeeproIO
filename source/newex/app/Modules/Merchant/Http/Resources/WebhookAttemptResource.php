<?php

namespace App\Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookAttemptResource extends JsonResource
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
            'attempt_number' => $this->attempt_number,
            'status' => $this->status,
            'response_code' => $this->response_code,
            'response_time_ms' => $this->response_time_ms,
            'error_message' => $this->error_message,
            'error_code' => $this->error_code,
            'attempted_at' => $this->attempted_at?->toIso8601String(),
        ];
    }
}
