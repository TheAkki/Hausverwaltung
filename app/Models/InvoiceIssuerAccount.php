<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Kundenaccount: one of possibly several customer accounts an InvoiceIssuer bills
 * under (e.g. separate accounts for electricity, water, district heating).
 */
#[Fillable(['invoice_issuer_id', 'customer_number', 'description', 'direct_debit', 'notes'])]
class InvoiceIssuerAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'direct_debit' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<InvoiceIssuer, $this>
     */
    public function invoiceIssuer(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuer::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
