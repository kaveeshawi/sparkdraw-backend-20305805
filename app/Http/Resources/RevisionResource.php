<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'project_id'     => $this->project_id,
            'round_number'   => $this->round_number,
            'feedback_text'  => $this->feedback_text,
            'status'         => $this->status,
            'has_ai_ticket'  => !is_null($this->ai_ticket_json),
            'ai_ticket_json' => $this->ai_ticket_json, // already cast to array by model
            'submitted_by'   => $this->whenLoaded('submittedBy', fn () => [
                'id'   => $this->submittedBy->id,
                'name' => $this->submittedBy->name,
            ]),
            'client'         => $this->whenLoaded('client', fn () => [
                'id'           => $this->client->id,
                'company_name' => $this->client->company_name,
            ]),
            'project'        => $this->whenLoaded('project', fn () => $this->project ? [
                'id'    => $this->project->id,
                'name'  => $this->project->name,
                'color' => $this->project->color,
            ] : null),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
