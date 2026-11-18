<?php

namespace App\Rules;

use App\Models\Lease;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Konzept.md "### Mietvertrag": "Eine Buchung ist dann für diesen Mietvertrag nicht
 * nötig, sondern wird mit der Buchung im referenzierten Mietvertrag verbucht. […]
 * Genierie bitte Fehlermeldungen, wenn Buchungen auf ein referenzierenden Mietvertrag
 * gebucht werden." A Mietzahlung may therefore not be given a Buchungsdatum directly on
 * a "referenzierenden" Mietvertrag (one coupled to another via "Mietvertrag: <ref>",
 * e.g. ein Stellplatz gekoppelt an die Wohnung desselben Mieters) – the tenant only
 * transfers rent once, and that payment is booked against the referenced Mietvertrag
 * instead. Clearing a Buchungsdatum back to open (null) is always allowed; only setting
 * one is rejected.
 */
class NotBookableOnCoupledLease implements ValidationRule
{
    public function __construct(private readonly Lease $lease) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $this->lease->coupled_to_lease_id === null) {
            return;
        }

        $fail('Dieser Mietvertrag ist an einen anderen Mietvertrag gekoppelt – die Mietzahlung wird dort gebucht, hier ist keine eigene Buchung nötig.');
    }
}
