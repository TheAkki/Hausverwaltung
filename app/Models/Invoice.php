<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An invoice from an InvoiceIssuer, which may recur (monthly/quarterly/yearly/manual).
 * Its polymorphic costable (ManagementGroup/Unit/RentalUnit) is the invoice's own
 * "Wirkungsrahmen" (scope), independent of how its amount is later split across cost
 * categories via Cost rows.
 */
#[Fillable([
    'invoice_issuer_id', 'invoice_issuer_account_id', 'costable_type', 'costable_id', 'amount', 'effective_amount',
    'invoice_number', 'invoice_date', 'booking_date', 'description', 'file_path', 'file_original_name', 'periodicity',
    'recurrence_start', 'recurrence_end', 'disable_amount_cap',
])]
class Invoice extends Model
{
    use HasFactory;

    public const PERIODICITY_ONCE = 'once';

    public const PERIODICITY_WEEKLY = 'weekly';

    public const PERIODICITY_BIWEEKLY = 'biweekly';

    public const PERIODICITY_MONTHLY = 'monthly';

    public const PERIODICITY_QUARTERLY = 'quarterly';

    public const PERIODICITY_YEARLY = 'yearly';

    public const PERIODICITY_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'booking_date' => 'date',
            'recurrence_start' => 'date',
            'recurrence_end' => 'date',
            'disable_amount_cap' => 'boolean',
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
     * @return BelongsTo<InvoiceIssuerAccount, $this>
     */
    public function invoiceIssuerAccount(): BelongsTo
    {
        return $this->belongsTo(InvoiceIssuerAccount::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function costable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<InvoiceOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(InvoiceOccurrence::class);
    }

    /**
     * @return HasMany<Cost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(Cost::class);
    }

    /**
     * Scopes invoices to those whose costable (ManagementGroup/Unit/RentalUnit,
     * whichever it directly targets) belongs to a management group the given user
     * is a member of.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $managementGroupIds = ManagementGroup::visibleTo($user)->pluck('id');
        $managementUnitIds = ManagementUnit::whereIn('management_group_id', $managementGroupIds)->pluck('id');
        $rentalUnitIds = RentalUnit::whereIn('management_unit_id', $managementUnitIds)->pluck('id');
        $leaseIds = Lease::whereIn('rental_unit_id', $rentalUnitIds)->pluck('id');

        return $query->where(function (Builder $q) use ($managementGroupIds, $managementUnitIds, $rentalUnitIds, $leaseIds) {
            $q->where(fn (Builder $q2) => $q2->where('costable_type', 'management_group')->whereIn('costable_id', $managementGroupIds))
                ->orWhere(fn (Builder $q2) => $q2->where('costable_type', 'management_unit')->whereIn('costable_id', $managementUnitIds))
                ->orWhere(fn (Builder $q2) => $q2->where('costable_type', 'rental_unit')->whereIn('costable_id', $rentalUnitIds))
                ->orWhere(fn (Builder $q2) => $q2->where('costable_type', 'lease')->whereIn('costable_id', $leaseIds));
        });
    }

    public function effectiveAmount(): float
    {
        return (float) ($this->effective_amount ?? $this->amount);
    }

    /**
     * "Erledigt" (Konzept.md "#### Übersicht aller Rechnungen"): every one of this
     * Rechnung's Cost rows has a booking_date, i.e. nothing about it is still "offen". A
     * Rechnung with no Cost rows at all (e.g. a future/manual invoice whose first
     * occurrence hasn't been generated/booked yet) is deliberately NOT "erledigt" – it
     * would otherwise vanish from the default (hide-completed) view despite nothing
     * having actually been booked yet.
     */
    public function isFullyBooked(): bool
    {
        return $this->costs->isNotEmpty() && $this->costs->every(fn (Cost $cost) => ! $cost->isOpen());
    }

    /**
     * "Buchungsdatum oder Buchungsbeginn" (Konzept.md "#### Übersicht aller Rechnungen"):
     * eine einmalige Rechnung hat höchstens ein einziges Buchungsdatum (ihr eigenes); eine
     * wiederkehrende hat potenziell viele (eine je Cost-Zeile) – hier interessiert das
     * früheste bereits tatsächlich gebuchte davon (noch offene Cost-Zeilen zählen nicht
     * mit). Erwartet die "costs"-Relation geladen zu haben (sonst N+1 pro Zeile in einer
     * Liste).
     */
    public function bookingRangeStart(): ?Carbon
    {
        return $this->periodicity === self::PERIODICITY_ONCE
            ? $this->booking_date
            : $this->costs->pluck('booking_date')->filter()->min();
    }

    /**
     * Gegenstück zu bookingRangeStart(): bei einer einmaligen Rechnung null – ein
     * einzelnes Datum hat kein "Ende", nur bookingRangeStart() zeigt es bereits. Bei
     * einer wiederkehrenden Rechnung das späteste bereits tatsächlich gebuchte Datum
     * ihrer Cost-Zeilen.
     */
    public function bookingRangeEnd(): ?Carbon
    {
        return $this->periodicity === self::PERIODICITY_ONCE
            ? null
            : $this->costs->pluck('booking_date')->filter()->max();
    }

    /**
     * German display label for the periodicity, since the DB value ("once", "monthly",
     * ...) has no matching translation key and __() would just echo it back untranslated.
     */
    public function periodicityLabel(): string
    {
        return match ($this->periodicity) {
            self::PERIODICITY_WEEKLY => 'Wöchentlich',
            self::PERIODICITY_BIWEEKLY => '2-wöchentlich',
            self::PERIODICITY_MONTHLY => 'Monatlich',
            self::PERIODICITY_QUARTERLY => 'Quartalsweise',
            self::PERIODICITY_YEARLY => 'Jährlich',
            self::PERIODICITY_MANUAL => 'Händisch',
            default => 'Einmalig',
        };
    }

    /**
     * The ManagementGroup that owns this invoice's costable scope, regardless of which
     * of the three costable types it was assigned to directly. Used for authorization
     * checks (a user may only see/edit invoices of groups they belong to).
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
     * Display label for the invoice's costable scope (Wirkungsrahmen), e.g.
     * "Verwaltungseinheit: Musterstraße 1".
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
}
