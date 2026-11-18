<?php

namespace App\Rules;

use App\Models\Lease;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates the "Vorvertrag" option: a pre-contract may only be flagged when it is NOT
 * the last Mietvertrag of its Mieteinheit – there must be a following lease (by start
 * date) that can absorb its period and Betriebskostenvorauszahlung (Konzept.md
 * "### Mieteinheit"). Only checked when the flag is actually set.
 */
class PreContractRequiresFollowingLease implements ValidationRule
{
    public function __construct(
        private readonly int $rentalUnitId,
        private readonly ?string $startDate,
        private readonly ?int $ignoreLeaseId = null,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value) {
            return;
        }

        $hasFollowingLease = Lease::query()
            ->where('rental_unit_id', $this->rentalUnitId)
            ->when($this->ignoreLeaseId, fn ($q) => $q->whereKeyNot($this->ignoreLeaseId))
            ->where('start_date', '>', $this->startDate)
            ->exists();

        if (! $hasFollowingLease) {
            $fail('Ein Vorvertrag ist nur möglich, wenn es einen nachfolgenden Mietvertrag für diese Mieteinheit gibt.');
        }
    }
}
