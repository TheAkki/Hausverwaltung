<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Represents a Verwaltungseinheit (property/building) belonging to a ManagementGroup,
 * containing multiple RentalUnits and directly costable via a polymorphic relation.
 */
#[Fillable(['management_group_id', 'street', 'postal_code', 'city', 'description'])]
class ManagementUnit extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<ManagementGroup, $this>
     */
    public function managementGroup(): BelongsTo
    {
        return $this->belongsTo(ManagementGroup::class);
    }

    /**
     * @return HasMany<RentalUnit, $this>
     */
    public function rentalUnits(): HasMany
    {
        return $this->hasMany(RentalUnit::class);
    }

    /**
     * @return MorphMany<Cost, $this>
     */
    public function costs(): MorphMany
    {
        return $this->morphMany(Cost::class, 'costable');
    }
}
