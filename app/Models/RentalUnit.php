<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Represents a single rental unit (Wohnung/Garage/Stellplatz) within a ManagementUnit,
 * with its own Leases and directly attributable Costs.
 */
#[Fillable(['management_unit_id', 'size', 'type', 'description', 'floor', 'is_relevant_for_billing'])]
class RentalUnit extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_relevant_for_billing' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ManagementUnit, $this>
     */
    public function managementUnit(): BelongsTo
    {
        return $this->belongsTo(ManagementUnit::class);
    }

    /**
     * @return HasMany<Lease, $this>
     */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /**
     * @return MorphMany<Cost, $this>
     */
    public function costs(): MorphMany
    {
        return $this->morphMany(Cost::class, 'costable');
    }

    /**
     * Forderungen (SettlementClaim) für den Leerstand dieser Mieteinheit (ohne
     * Mietvertrag), siehe SettlementClaim::isForVacancy().
     *
     * @return HasMany<SettlementClaim, $this>
     */
    public function settlementClaims(): HasMany
    {
        return $this->hasMany(SettlementClaim::class);
    }

    public function label(): string
    {
        return $this->description ? "{$this->type} ({$this->description})" : $this->type;
    }
}
