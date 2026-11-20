<?php

namespace App\Models;

use App\Models\Pivots\AccountRentPayment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single month's expected rent payment for a LeaseInterval. The booking_date is
 * set once the payment has actually been booked; a null booking_date for an elapsed
 * period marks it as an "offene Buchung" (outstanding booking).
 */
#[Fillable(['lease_interval_id', 'period_date', 'amount', 'rent_amount', 'utility_amount', 'booking_date'])]
class RentPayment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'booking_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<LeaseInterval, $this>
     */
    public function leaseInterval(): BelongsTo
    {
        return $this->belongsTo(LeaseInterval::class);
    }

    /**
     * @return BelongsToMany<Account, $this>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_rent_payment')->using(AccountRentPayment::class)->withPivot('amount')->withTimestamps();
    }

    /**
     * Aufschlüsselung, welcher Mietvertrag wie viel zu dieser Mietzahlung beigetragen
     * hat (Konzept.md "### Mietvertrag") – neben dem Mietvertrag selbst eine weitere
     * Zeile pro in diesem Monat aktiv gekoppeltem Mietvertrag (z. B. ein Stellplatz),
     * dessen Miete nie separat gebucht wird, sondern hier mit einfließt. Von
     * RentPaymentGenerator automatisch gepflegt, solange die Zahlung noch offen ist.
     *
     * @return BelongsToMany<Lease, $this>
     */
    public function contributingLeases(): BelongsToMany
    {
        return $this->belongsToMany(Lease::class, 'lease_rent_payment')->withPivot('amount')->withTimestamps();
    }

    public function isOpen(): bool
    {
        return $this->booking_date === null;
    }
}
