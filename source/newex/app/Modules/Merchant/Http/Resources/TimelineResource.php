<?php

namespace App\Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimelineResource extends JsonResource
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
            'event_type' => $this->event_type,
            'source' => $this->source,
            'old_status' => $this->old_status,
            'new_status' => $this->new_status,
            'event_data' => $this->event_data,
            'actor_type' => $this->actor_type,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
