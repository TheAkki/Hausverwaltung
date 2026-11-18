<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Represents a single billed cost occurrence tied to an Invoice, a BillingInterval,
 * a CostCategory and a polymorphic costable (ManagementGroup/Unit/RentalUnit).
 */
#[Fillable(['invoice_id', 'costable_type', 'costable_id', 'billing_interval_id', 'cost_category_id', 'amount', 'period_date', 'booking_date', 'note'])]
class Cost extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'booking_date' => 'date',
        ];
    }

    public function isOpen(): bool
    {
        return $this->booking_date === null;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function costable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<BillingInterval, $this>
     */
    public function billingInterval(): BelongsTo
    {
        return $this->belongsTo(BillingInterval::class);
    }

    /**
     * @return BelongsTo<CostCategory, $this>
     */
    public function costCategory(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class);
    }

    /**
     * @return BelongsToMany<Account, $this>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_cost')->withPivot('amount')->withTimestamps();
    }

    /**
     * The ManagementGroup this cost's costable belongs to, used to resolve which
     * Konten (accounts) are selectable for it when the Kontensystem is enabled.
     */
    public function managementGroup(): ?ManagementGroup
    {
        return match (true) {
            $this->costable instanceof ManagementGroup => $this->costable,
            $this->costable instanceof ManagementUnit => $this->costable->managementGroup,
            $this->costable instanceof RentalUnit => $this->costable->managementUnit->managementGroup,
            $this->costable instanceof Lease => $this->costable->rentalUnit->managementUnit->managementGroup,
            default => null,
        };
    }

    /**
     * Display label for this cost's own costable (Zuordnung), e.g. "Mieteinheit:
     * Garage" – mirrors Invoice::costableLabel(), but for the Cost's own costable_type/
     * costable_id rather than its parent Invoice's: a "Kostenzeile hinzufügen" can
     * assign a cost to a different costable than the Rechnung's own (e.g. splitting a
     * Sammelrechnung across several Mieteinheiten), so the two can genuinely differ.
     */
    public function costableLabel(): string
    {
        return match (true) {
            $this->costable instanceof ManagementGroup => 'Verwaltungsgruppe: '.($this->costable->description ?? "#{$this->costable->id}"),
            $this->costable instanceof ManagementUnit => 'Verwaltungseinheit: '.$this->costable->street,
            $this->costable instanceof RentalUnit => 'Mieteinheit: '.$this->costable->label(),
            $this->costable instanceof Lease => 'Mietvertrag: '.$this->costable->tenantNames(),
            default => '–',
        };
    }

    /**
     * Just the "Zuordnungsebene" name (Konzept.md "#### Template": Buchungen-Übersicht
     * "gruppiert nach Zuordnungsebene und danach nach Kategorie") – the same four levels
     * as costableLabel(), but without the instance-specific detail after the colon, so
     * bookings from different Verwaltungseinheiten/Mieteinheiten still group under one
     * shared "Verwaltungseinheit"/"Mieteinheit" heading.
     */
    public function assignmentLevelLabel(): string
    {
        return match (true) {
            $this->costable instanceof ManagementGroup => 'Verwaltungsgruppe',
            $this->costable instanceof ManagementUnit => 'Verwaltungseinheit',
            $this->costable instanceof RentalUnit => 'Mieteinheit',
            $this->costable instanceof Lease => 'Mietvertrag',
            default => '–',
        };
    }
}
