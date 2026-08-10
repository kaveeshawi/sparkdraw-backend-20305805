<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lineItems = $this->line_items ?? [];
        $subtotal  = collect($lineItems)->sum(fn ($item) => (float) ($item['amount'] ?? 0));
        $tax       = 0;
        $total     = (float) $this->amount;
        $status    = $this->displayStatus();

        return [
            'id'             => $this->id,
            'invoice_number' => $this->invoice_number,
            'client_id'      => $this->client_id,
            'client_name'    => $this->client?->company_name,
            'client'         => $this->whenLoaded('client', fn () => [
                'id'           => $this->client->id,
                'company_name' => $this->client->company_name,
                'contact_name' => $this->client->contactUser?->name,
                'contact_email'=> $this->client->contactUser?->email,
            ]),
            'project_id'     => $this->project_id,
            'project_name'   => $this->project?->name,
            'project'        => $this->whenLoaded('project', fn () => [
                'id'   => $this->project->id,
                'name' => $this->project->name,
            ]),
            'line_items'     => $lineItems,
            'subtotal'       => round($subtotal, 2),
            'tax'            => $tax,
            'total'          => $total,
            'amount'         => $total,
            'status'         => $status,
            'due_date'       => $this->due_date?->toDateString(),
            'notes'          => $this->notes,
            'paid_at'        => $this->paid_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
