<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'status'              => $this->status,
            'requested_at'        => $this->requested_at?->toIso8601String(),
            'responded_at'        => $this->responded_at?->toIso8601String(),
            'approval_lag_hours'  => $this->approval_lag_hours,
            'deliverable'         => $this->whenLoaded('deliverable', fn () => $this->deliverable ? [
                'id'            => $this->deliverable->id,
                'original_name' => $this->deliverable->original_name,
                'file_path'     => $this->deliverable->file_path,
                'version'       => $this->deliverable->version,
            ] : null),
            'requested_by'        => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? [
                'id'   => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ] : null),
            'client'              => $this->whenLoaded('client', fn () => $this->client ? [
                'id'           => $this->client->id,
                'company_name' => $this->client->company_name,
            ] : null),
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
