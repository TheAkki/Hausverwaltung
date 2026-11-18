<?php

namespace App\Services\InvoiceSeries;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use Illuminate\Database\Eloquent\Model;

/**
 * Die Verwaltungsgruppe, zu der eine Kostenstelle (Verwaltungsgruppe, -einheit,
 * Mieteinheit oder Mietvertrag) gehört.
 */
final class CostableManagementGroup
{
    public static function of(Model $costable): ManagementGroup
    {
        return match (true) {
            $costable instanceof ManagementGroup => $costable,
            $costable instanceof ManagementUnit => $costable->managementGroup,
            $costable instanceof RentalUnit => $costable->managementUnit->managementGroup,
            $costable instanceof Lease => $costable->rentalUnit->managementUnit->managementGroup,
            default => throw new \InvalidArgumentException('Unsupported costable type: '.$costable::class),
        };
    }
}
