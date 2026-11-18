<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single scheduled occurrence (date and amount) of a recurring or manually-scheduled Invoice.
 */
#[Fillable(['invoice_id', 'occurrence_date', 'amount'])]
class InvoiceOccurrence extends Model
{
    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
