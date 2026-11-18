<?php

namespace App\Models;

use App\Observers\LeaseObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A Mietvertrag (rental lease) on a RentalUnit, linking Tenants and tracking rent
 * adjustment over time via its LeaseIntervals and attached LeaseDocuments.
 */
#[Fillable([
    'rental_unit_id', 'coupled_to_lease_id', 'start_date', 'end_date', 'is_pre_contract', 'rent_adjustment_type',
    'cold_rent', 'deposit_amount', 'min_term_months', 'notice_period', 'notes',
])]
#[ObservedBy(LeaseObserver::class)]
class Lease extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_pre_contract' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withTimestamps();
    }

    public function tenantNames(): string
    {
        return $this->tenants->map->fullName()->join(', ');
    }

    /**
     * @return BelongsTo<RentalUnit, $this>
     */
    public function rentalUnit(): BelongsTo
    {
        return $this->belongsTo(RentalUnit::class);
    }

    /**
     * The Mietvertrag this one is coupled to via a "Mietvertrag: <ref>" reference
     * (Konzept.md "### Mietvertrag"), e.g. a Stellplatz coupled to the tenant's
     * Wohnung – null for a Mietvertrag with its own MieterId. Used by the
     * Steuerübersicht (FinancialOverviewService::taxOverviewIncomeByFloor()) to sum
     * this Mietvertrag's Mieteinnahmen/Nebenkosten into the referenced one instead of
     * listing it as its own row.
     *
     * @return BelongsTo<Lease, $this>
     */
    public function coupledToLease(): BelongsTo
    {
        return $this->belongsTo(self::class, 'coupled_to_lease_id');
    }

    /**
     * Every RentPayment (of any Mietvertrag) this Mietvertrag contributed an amount
     * to – for a "normal" Mietvertrag just its own generated Mietzahlungen; for a
     * gekoppelter Mietvertrag (see coupledToLease()) the referenced Mietvertrag's
     * Mietzahlungen it was folded into instead, since it is never booked itself.
     *
     * @return BelongsToMany<RentPayment, $this>
     */
    public function rentPaymentContributions(): BelongsToMany
    {
        return $this->belongsToMany(RentPayment::class, 'lease_rent_payment')->withPivot('amount')->withTimestamps();
    }

    /**
     * @return HasMany<LeaseInterval, $this>
     */
    public function leaseIntervals(): HasMany
    {
        return $this->hasMany(LeaseInterval::class);
    }

    /**
     * @return HasMany<LeaseDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(LeaseDocument::class);
    }

    public function currentLeaseInterval(): ?LeaseInterval
    {
        return $this->leaseIntervals()->latest('start_date')->first();
    }

    /**
     * The next Mietvertrag (chronologically by start_date) on the same Mieteinheit. Used
     * to reference a "Vorvertrag" to the contract it is absorbed into for billing
     * (BillingStatementLeaseResolver), so the overview can link the two together.
     */
    public function successorLease(): ?self
    {
        return static::where('rental_unit_id', $this->rental_unit_id)
            ->where('start_date', '>', $this->start_date)
            ->orderBy('start_date')
            ->first();
    }

    public function isExpired(): bool
    {
        return $this->end_date !== null && $this->end_date->isPast();
    }

    /**
     * Individuell zugewiesene Kosten (Kostenzuweisung): nur für Kategorien mit
     * Abrechnungsart "individuell" nutzbar, siehe CostCategoryMatchesCostableAllocation.
     * Diese Kosten gehen nicht in die Kontensystem-Bilanzierung ein, da sie nur extern
     * ermittelt wurden (siehe CostController).
     *
     * @return MorphMany<Cost, $this>
     */
    public function costs(): MorphMany
    {
        return $this->morphMany(Cost::class, 'costable');
    }

    /**
     * @return HasMany<SettlementClaim, $this>
     */
    public function settlementClaims(): HasMany
    {
        return $this->hasMany(SettlementClaim::class);
    }
}
