<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a maintenance/service contract (e.g. Hauswart, Aufzugswartung) belonging
 * to a ManagementGroup, with a provider and validity period.
 */
#[Fillable(['management_group_id', 'name', 'type', 'provider', 'start_date', 'end_date'])]
class MaintenanceContract extends Model
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
