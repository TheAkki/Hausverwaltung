<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A Rechnung is either an Ausgabe (positive amount) or an Einnahme (negative amount,
 * Rückzahlung/Gutschrift) – partial amounts belonging to it (wirksamer Betrag,
 * händische Termine) must therefore carry the same sign as the reference amount. A
 * non-numeric reference (not yet validated/missing) imposes no restriction.
 */
class SameSignAs implements ValidationRule
{
    public function __construct(private readonly mixed $reference) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($this->reference) || ! is_numeric($value)) {
            return;
        }

        $referenceIsNegative = (float) $this->reference < 0;

        if ($referenceIsNegative ? (float) $value > 0 : (float) $value < 0) {
            $fail('Der Betrag muss dasselbe Vorzeichen wie der Rechnungsbetrag haben (positiv = Ausgabe, negativ = Einnahme).');
        }
    }
}
