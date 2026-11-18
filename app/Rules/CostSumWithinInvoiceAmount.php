<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that adding a new cost amount keeps the sum of the invoice's cost amounts
 * between 0 and the (effective) invoice amount. Sign-aware: for a negative invoice
 * (Rückzahlung/Gutschrift) the sum must lie between the negative cap and 0, so a
 * cost row can neither exceed the refund nor carry the opposite sign.
 */
class CostSumWithinInvoiceAmount implements ValidationRule
{
    public function __construct(
        private readonly float $existingAmountSum,
        private readonly float $cap,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Rounded to cents before comparing: floating-point addition of two amounts
        // that sum exactly on paper (e.g. 2358.38 + 836.22 = 3194.60) can otherwise
        // land a hair above the cap (3194.6000000000004), failing this check for a
        // perfectly valid split.
        $sum = round($this->existingAmountSum + (float) $value, 2);
        $cap = round($this->cap, 2);

        if ($sum < min(0.0, $cap) || $sum > max(0.0, $cap)) {
            $fail('Die Summe der Kostenbeträge darf den (wirksamen) Rechnungsbetrag nicht übersteigen.');
        }
    }
}
