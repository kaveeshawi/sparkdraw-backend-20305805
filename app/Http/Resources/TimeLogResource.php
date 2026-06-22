<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimeLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'hours'       => (float) $this->hours,
            'logged_date' => $this->logged_date?->toDateString(),
            'notes'       => $this->notes,
            'user'        => $this->whenLoaded('user', fn() => [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ]),
            'created_at'  => $this->created_at,
        ];
    }
}
