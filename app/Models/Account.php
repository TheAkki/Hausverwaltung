<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Konto (ledger account) belonging to a ManagementGroup, only relevant when that
 * group's owning Benutzergruppe has the Kontensystem enabled. Tracks a booking-neutral
 * initial_balance, can be marked as the group's default account, and receives Cost
 * (Ausgabe) allocations and inter-account transfers.
 */
#[Fillable(['management_group_id', 'name', 'description', 'initial_balance', 'is_default'])]
class Account extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ManagementGroup, $this>
     */
    public function managementGroup(): BelongsTo
    {
        return $this->belongsTo(ManagementGroup::class);
    }

    /**
     * @return BelongsToMany<Cost, $this>
     */
    public function costs(): BelongsToMany
    {
        return $this->belongsToMany(Cost::class, 'account_cost')->withPivot('amount')->withTimestamps();
    }

    /**
     * @return BelongsToMany<RentPayment, $this>
     */
    public function rentPayments(): BelongsToMany
    {
        return $this->belongsToMany(RentPayment::class, 'account_rent_payment')->withPivot('amount')->withTimestamps();
    }

    /**
     * @return HasMany<AccountTransfer, $this>
     */
    public function transfersOut(): HasMany
    {
        return $this->hasMany(AccountTransfer::class, 'from_account_id');
    }

    /**
     * @return HasMany<AccountTransfer, $this>
     */
    public function transfersIn(): HasMany
    {
        return $this->hasMany(AccountTransfer::class, 'to_account_id');
    }
}
