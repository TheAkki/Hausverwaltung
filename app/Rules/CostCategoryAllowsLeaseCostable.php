<?php

namespace App\Rules;

use App\Models\ManagementGroup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A Cost can only be assigned directly to a Mietvertrag (Lease) when its
 * CostCategory's Abrechnungsart (allocation_key, scoped per ManagementGroup) is
 * "individual" – all other allocation keys are computed from Kosten booked at
 * Verwaltungsgruppe/-einheit/Mieteinheit level instead (see "Kostenzuweisung" in
 * Konzept.md). Only applies when the cost is actually being assigned to a Lease;
 * a no-op otherwise.
 */
class CostCategoryAllowsLeaseCostable implements ValidationRule
{
    public function __construct(
        private readonly bool $costableIsLease,
        private readonly ?ManagementGroup $managementGroup,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->costableIsLease) {
            return;
        }

        $allocationKey = $this->managementGroup
            ?->costCategories()
            ->where('cost_categories.id', $value)
            ->first()
            ?->pivot
            ->allocation_key;

        if ($allocationKey !== 'individual') {
            $fail('Kosten können einem Mietvertrag nur zugewiesen werden, wenn die Kategorie in dieser Verwaltungsgruppe die Abrechnungsart "Individuell" hat.');
        }
    }
}
