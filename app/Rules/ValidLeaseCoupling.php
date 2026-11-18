<?php

namespace App\Rules;

use App\Models\Lease;
use App\Models\RentalUnit;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a "Mietvertrag: <ref>"-Kopplung (Konzept.md "### Mietvertrag" /
 * "### Detailbild Mieteinheit") created via the live Oberfläche, mirroring the
 * restrictions SeedsLeases::resolveLeaseReference()/assertCoupledLeaseDoesNotOutliveReference()
 * enforce for the YAML-Seeder:
 * - the coupled ("referenzierende") Mieteinheit must NOT itself be abrechnungsrelevant,
 * - the referenced Mietvertrag's Mieteinheit MUST be abrechnungsrelevant,
 * - both must belong to the same Verwaltungseinheit,
 * - the referenced Mietvertrag must belong to a DIFFERENT Mieteinheit,
 * - the coupled Mietvertrag may not end after the referenced one (an open-ended
 *   referenced Mietvertrag imposes no limit).
 */
class ValidLeaseCoupling implements ValidationRule
{
    public function __construct(
        private readonly int $rentalUnitId,
        private readonly ?string $endDate,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $targetLease = Lease::with('rentalUnit')->find($value);

        if ($targetLease === null) {
            $fail('Der referenzierte Mietvertrag wurde nicht gefunden.');

            return;
        }

        $rentalUnit = RentalUnit::find($this->rentalUnitId);

        if ($rentalUnit === null) {
            return;
        }

        // Checked BEFORE the abrechnungsrelevant checks below (matching
        // SeedsLeases::resolveLeaseReference()'s order for the YAML-Seeder): for a
        // self-reference, $rentalUnit and $targetLease->rentalUnit are the very same
        // row, so they'd always agree on is_relevant_for_billing – the later checks
        // could never actually catch this case and would misreport it via the wrong
        // message (or not at all).
        if ($targetLease->rental_unit_id === $this->rentalUnitId) {
            $fail('Es kann nicht auf einen anderen Mietvertrag der selben Mieteinheit referenziert werden.');

            return;
        }

        if ($rentalUnit->is_relevant_for_billing) {
            $fail('Eine abrechnungsrelevante Mieteinheit darf nicht per Mietvertrag-Referenz gekoppelt werden.');

            return;
        }

        if (! $targetLease->rentalUnit->is_relevant_for_billing) {
            $fail('Der referenzierte Mietvertrag muss zu einer abrechnungsrelevanten Mieteinheit gehören.');

            return;
        }

        if ($targetLease->rentalUnit->management_unit_id !== $rentalUnit->management_unit_id) {
            $fail('Es kann nur auf einen Mietvertrag derselben Verwaltungseinheit referenziert werden.');

            return;
        }

        if ($targetLease->end_date !== null
            && ($this->endDate === null || Carbon::parse($this->endDate)->gt($targetLease->end_date))) {
            $fail('Der Mietvertrag darf nicht nach dem referenzierten Mietvertrag enden.');
        }
    }
}
