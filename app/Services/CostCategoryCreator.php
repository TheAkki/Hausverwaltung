<?php

namespace App\Services;

use App\Models\CostCategory;
use Illuminate\Validation\ValidationException;

/**
 * Legt eine eigene Kostenkategorie an – eine Logik für Web und API.
 *
 * - Eine Überkategorie macht daraus eine Unterkategorie ("<Überkategorie>::<Name>");
 *   sie stammt aus derselben Gruppe oder ist eine Systemkategorie.
 * - Ein Name ist innerhalb der Gruppe (samt Systemkategorien) eindeutig. Andere Gruppen
 *   dürfen denselben Namen haben – etwa "Lohnkosten" in persönlicher und gemeinsamer
 *   Gruppe; ob es ihn dort gibt, verrät die Prüfung nicht.
 */
final class CostCategoryCreator
{
    public const FIELD_PARENT = 'parent_id';

    public const FIELD_NAME = 'name';

    /**
     * @param  array{user_group_id: int|string, name: string, parent_id?: int|string|null}  $validated
     */
    public function create(array $validated, bool $relevantForBilling, bool $taxRelevant, ?CostCategory $parent = null): CostCategory
    {
        $groupId = (int) $validated['user_group_id'];

        if ($parent !== null && $parent->user_group_id !== null && $parent->user_group_id !== $groupId) {
            throw ValidationException::withMessages([
                self::FIELD_PARENT => __('Die Überkategorie gehört zu einer anderen Gruppe.'),
            ]);
        }

        $fullName = $parent !== null ? $parent->name.CostCategory::SEPARATOR.$validated['name'] : $validated['name'];

        if (CostCategory::where('name', $fullName)->inGroupOrSystem($groupId)->exists()) {
            throw ValidationException::withMessages([
                self::FIELD_NAME => __('Eine Kostenkategorie mit diesem Namen existiert bereits.'),
            ]);
        }

        return CostCategory::create([
            'user_group_id' => $groupId,
            'name' => $fullName,
            'is_relevant_for_billing' => $relevantForBilling,
            'is_tax_relevant' => $taxRelevant,
        ]);
    }
}
