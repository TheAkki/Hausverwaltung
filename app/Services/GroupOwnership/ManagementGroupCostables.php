<?php

namespace App\Services\GroupOwnership;

use App\Models\Cost;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alle Kostenstellen einer Verwaltungsgruppe (sie selbst, ihre Verwaltungseinheiten,
 * Mieteinheiten und Mietverträge) und die Kostenzeilen und Rechnungen darauf.
 */
final class ManagementGroupCostables
{
    /**
     * @return array<string, list<int>> Morph-Alias => Ids
     */
    public static function idsOf(ManagementGroup $managementGroup): array
    {
        $unitIds = ManagementUnit::where('management_group_id', $managementGroup->id)->pluck('id')->all();
        $rentalUnitIds = RentalUnit::whereIn('management_unit_id', $unitIds)->pluck('id')->all();

        return [
            $managementGroup->getMorphClass() => [$managementGroup->id],
            (new ManagementUnit)->getMorphClass() => $unitIds,
            (new RentalUnit)->getMorphClass() => $rentalUnitIds,
            (new Lease)->getMorphClass() => self::leaseIdsOf($managementGroup, $rentalUnitIds),
        ];
    }

    /**
     * @param  list<int>|null  $rentalUnitIds
     * @return list<int>
     */
    public static function leaseIdsOf(ManagementGroup $managementGroup, ?array $rentalUnitIds = null): array
    {
        $rentalUnitIds ??= RentalUnit::whereHas(
            'managementUnit', fn (Builder $q) => $q->where('management_group_id', $managementGroup->id)
        )->pluck('id')->all();

        return Lease::whereIn('rental_unit_id', $rentalUnitIds)->pluck('id')->all();
    }

    /**
     * Schränkt eine Abfrage mit costable_type/costable_id auf die Kostenstellen ein.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, list<int>>  $idsByType
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query, array $idsByType): Builder
    {
        return $query->where(function (Builder $outer) use ($idsByType) {
            foreach ($idsByType as $type => $ids) {
                $outer->orWhere(fn (Builder $q) => $q->where('costable_type', $type)->whereIn('costable_id', $ids));
            }
        });
    }

    /**
     * @param  array<string, list<int>>  $idsByType
     * @return Builder<Cost>
     */
    public static function costs(array $idsByType): Builder
    {
        return self::constrain(Cost::query(), $idsByType);
    }

    /**
     * Rechnungen, die der Verwaltungsgruppe direkt zugeordnet sind oder Kostenzeilen
     * auf ihr haben.
     *
     * @param  array<string, list<int>>  $idsByType
     * @return Builder<Invoice>
     */
    public static function invoices(array $idsByType): Builder
    {
        return Invoice::query()->where(
            fn (Builder $q) => self::constrain($q, $idsByType)
                ->orWhereHas('costs', fn (Builder $costs) => self::constrain($costs, $idsByType))
        );
    }
}
