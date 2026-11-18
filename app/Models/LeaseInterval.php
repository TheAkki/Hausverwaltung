<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A time-bounded rent/occupancy interval belonging to a Lease, tracking rent amount,
 * occupant count, and utility advance payment for a given period.
 */
#[Fillable(['lease_id', 'start_date', 'end_date', 'rent_amount', 'occupant_count', 'utility_advance_payment', 'notes'])]
class LeaseInterval extends Model
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
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function totalMonthlyAmount(): float
    {
        return (float) $this->rent_amount + (float) $this->utility_advance_payment;
    }

    /**
     * @return HasMany<RentPayment, $this>
     */
    public function rentPayments(): HasMany
    {
        return $this->hasMany(RentPayment::class);
    }

    /**
     * Removes RentPayment rows whose period is now beyond this interval's end_date,
     * e.g. after a Mietvertrag was ended earlier than previously assumed. Only still-open
     * (unbooked) rows are removed — an already booked payment is a confirmed historical
     * record and must not be silently deleted.
     */
    public function pruneOpenRentPaymentsBeyondEndDate(): void
    {
        if ($this->end_date === null) {
            return;
        }

        $this->rentPayments()
            ->whereNull('booking_date')
            ->where('period_date', '>', $this->end_date)
            ->delete();
    }
}
