<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a billing period (Abrechnungszeitraum) belonging to a ManagementGroup,
 * over which costs and invoice occurrences are grouped.
 */
#[Fillable(['management_group_id', 'start_date', 'end_date', 'description'])]
class BillingInterval extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<ManagementGroup, $this>
     */
    public function managementGroup(): BelongsTo
    {
        return $this->belongsTo(ManagementGroup::class);
    }
}
