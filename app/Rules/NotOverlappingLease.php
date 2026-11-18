<?php

namespace App\Rules;

use App\Models\Lease;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that a lease's date range does not overlap with another existing lease
 * on the same rental unit.
 */
class NotOverlappingLease implements ValidationRule
{
    public function __construct(
        private readonly int $rentalUnitId,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
        private readonly ?int $ignoreLeaseId = null,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $overlaps = Lease::query()
            ->where('rental_unit_id', $this->rentalUnitId)
            ->when($this->ignoreLeaseId, fn ($q) => $q->whereKeyNot($this->ignoreLeaseId))
            ->where(fn ($q) => $q
                ->whereNull('end_date')
                ->orWhere('end_date', '>=', $this->startDate)
            )
            ->when($this->endDate, fn ($q) => $q->where('start_date', '<=', $this->endDate))
            ->exists();

        if ($overlaps) {
            $fail('Der Mietvertrag überschneidet sich mit einem bestehenden Mietvertrag für diese Mieteinheit.');
        }
    }
}
