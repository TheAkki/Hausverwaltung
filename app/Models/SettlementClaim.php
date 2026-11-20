<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Betriebskostennachzahlung (positive amount) or -rückzahlung (negative amount),
 * tied to the BillingInterval it settles ("Forderung" in Konzept.md). Recorded as its
 * own bookable event – rather than only the live, ever-changing computed BKA balance
 * (BillingStatementController::render()'s 'balance') – so the Steuerübersicht has a
 * historized amount per Abrechnungsjahr to report on.
 *
 * Belongs to EITHER a Mietvertrag (lease_id – the usual case) OR a Mieteinheit
 * (rental_unit_id – Konzept.md "#### Berechnung": "Zu diesem Leerstand müssen auch
 * Forderungen, die z. B. von einer Abrechnungsgesellschaft kommen, verbucht werden."
 * für eine zeitweise leerstehende, abrechnungsrelevante Mieteinheit ohne Mietvertrag),
 * nie beide, nie keines – enforced in SettlementClaimController, not by a DB
 * constraint.
 */
#[Fillable(['lease_id', 'rental_unit_id', 'billing_interval_id', 'amount', 'date', 'booking_date', 'notes', 'file_path', 'file_original_name'])]
class SettlementClaim extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'booking_date' => 'date',
        ];
    }

    public function isOpen(): bool
    {
        return $this->booking_date === null;
    }

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * @return BelongsTo<RentalUnit, $this>
     */
    public function rentalUnit(): BelongsTo
    {
        return $this->belongsTo(RentalUnit::class);
    }

    /**
     * @return BelongsTo<BillingInterval, $this>
     */
    public function billingInterval(): BelongsTo
    {
        return $this->belongsTo(BillingInterval::class);
    }

    /**
     * True for a Leerstands-Forderung (an dieser Mieteinheit, kein Mietvertrag), false
     * für die übliche, an einen Mietvertrag gebundene Forderung.
     */
    public function isForVacancy(): bool
    {
        return $this->rental_unit_id !== null;
    }

    /**
     * The ManagementGroup this Forderung's Mietvertrag bzw. Mieteinheit gehört, für
     * Autorisierung/Abrechnungsintervall-Filterung unabhängig davon, welche der beiden
     * Zuordnungen gesetzt ist.
     */
    public function managementGroup(): ?ManagementGroup
    {
        return match (true) {
            $this->lease_id !== null => $this->lease->rentalUnit->managementUnit->managementGroup,
            $this->rental_unit_id !== null => $this->rentalUnit->managementUnit->managementGroup,
            default => null,
        };
    }
}
