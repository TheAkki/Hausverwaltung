<?php

namespace App\Models\Pivots;

use App\Models\Cost;
use App\Services\GroupOwnership\GroupOwnership;
use App\Services\GroupOwnership\ReferenceIntegrity;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Konto einer Kostenzeile (Kontensystem). Das Konto gehört zur Verwaltungsgruppe der
 * Zuordnung der Kostenzeile (siehe ReferenceIntegrity).
 */
class AccountCost extends Pivot
{
    protected $table = 'account_cost';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            if (GroupOwnership::isSuspended()) {
                return;
            }

            $cost = Cost::find($pivot->cost_id);

            ReferenceIntegrity::assertAccountBelongsTo($pivot->account_id, $cost?->managementGroup());
        });
    }
}
