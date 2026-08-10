<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id', 'client_id', 'project_id', 'invoice_number',
        'amount', 'line_items', 'status', 'due_date', 'notes',
        'paypal_order_id', 'paid_at',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'line_items' => 'array',
        'due_date'   => 'date',
        'paid_at'    => 'datetime',
    ];

    public static function generateInvoiceNumber(int $agencyId): string
    {
        $year  = now()->year;
        $count = static::withoutAgencyScope()
            ->where('agency_id', $agencyId)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf('INV-%d-%03d', $year, $count);
    }

    public function displayStatus(): string
    {
        if ($this->status === 'paid') {
            return 'paid';
        }

        if ($this->status === 'sent' && $this->due_date && $this->due_date->isPast()) {
            return 'overdue';
        }

        return $this->status;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
