<?php

namespace App\Models;

use App\Models\Pivots\ManagementGroupCostCategory;
use App\Observers\ManagementGroupObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A Verwaltungsgruppe (management group) owned by a Group, aggregating ManagementUnits,
 * BillingIntervals, costs, and maintenance contracts for shared cost allocation and billing.
 */
#[Fillable([
    'description', 'billing_start', 'user_group_id', 'default_billing_template_id', 'kontosystem_enabled',
    'variable_cost_forecast_mode',
])]
#[ObservedBy(ManagementGroupObserver::class)]
class ManagementGroup extends Model
{
    use HasFactory;

    /**
     * A still-open (not yet booked) forecast row for a Rechnung/Kostenkategorie combination
     * that is "variable" (see ForecastRefresher – its Betragsüberprüfung is
     * disabled, or at least two already-booked occurrences of the same category booked
     * different amounts) is simply never generated: the period stays absent from the
     * Ausgabenübersicht until it is actually booked, rather than pretending to know a number.
     */
    public const VARIABLE_FORECAST_NONE = 'none';

    /**
     * A variable Rechnung's still-open forecast rows carry forward the most recently
     * booked real amount for that Kostenkategorie, instead of the (often meaningless, e.g.
     * 0 for a category with no fixed nominal split) baseline guess.
     */
    public const VARIABLE_FORECAST_LAST_BOOKING = 'last_booking';

    /**
     * A variable Rechnung's still-open forecast rows extrapolate a linear trend (least
     * squares Ausgleichsfunktion) fitted to that Kostenkategorie's already-booked amounts –
     * e.g. a Kredit's Tilgung/Zinsen-Anteil, which shifts by roughly the same amount every
     * period, is projected forward instead of frozen at the last known value.
     */
    public const VARIABLE_FORECAST_REGRESSION = 'regression';

    public const VARIABLE_FORECAST_MODES = [
        self::VARIABLE_FORECAST_NONE,
        self::VARIABLE_FORECAST_LAST_BOOKING,
        self::VARIABLE_FORECAST_REGRESSION,
    ];

    protected function casts(): array
    {
        return [
            'billing_start' => 'date',
            'kontosystem_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'user_group_id');
    }

    /**
     * @return HasMany<ManagementUnit, $this>
     */
    public function managementUnits(): HasMany
    {
        return $this->hasMany(ManagementUnit::class);
    }

    /**
     * @return HasMany<BillingInterval, $this>
     */
    public function billingIntervals(): HasMany
    {
        return $this->hasMany(BillingInterval::class);
    }

    /**
     * @return MorphMany<Cost, $this>
     */
    public function costs(): MorphMany
    {
        return $this->morphMany(Cost::class, 'costable');
    }

    /**
     * @return BelongsToMany<CostCategory, $this>
     */
    public function costCategories(): BelongsToMany
    {
        return $this->belongsToMany(CostCategory::class, 'management_group_cost_category')
            ->using(ManagementGroupCostCategory::class)
            ->withPivot('allocation_key')
            ->withTimestamps();
    }

    /**
     * @return HasMany<MaintenanceContract, $this>
     */
    public function maintenanceContracts(): HasMany
    {
        return $this->hasMany(MaintenanceContract::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function defaultAccount(): ?Account
    {
        return $this->accounts->firstWhere('is_default', true) ?? $this->accounts->first();
    }

    public function kontosystemEnabled(): bool
    {
        return (bool) $this->kontosystem_enabled;
    }

    /**
     * Whether this Verwaltungsgruppe's Benutzergruppe has more than one member, i.e. it is
     * shared across multiple Accounts rather than owned by a single user alone.
     */
    public function isShared(): bool
    {
        return ($this->userGroup?->users->count() ?? 0) > 1;
    }

    /**
     * @return BelongsTo<BillingTemplate, $this>
     */
    public function defaultBillingTemplate(): BelongsTo
    {
        return $this->belongsTo(BillingTemplate::class, 'default_billing_template_id');
    }

    /**
     * This Verwaltungsgruppe's own Vermieter-override, if one is set.
     *
     * @return HasOne<Landlord, $this>
     */
    public function landlord(): HasOne
    {
        return $this->hasOne(Landlord::class);
    }

    /**
     * The Vermieter to use on this Verwaltungsgruppe's Betriebskostenabrechnung: its own
     * override if one is set, otherwise the account-level Vermieter of the owning
     * Benutzergruppe. May be null if neither is configured.
     */
    public function effectiveLandlord(): ?Landlord
    {
        return $this->landlord
            ?? Landlord::where('user_group_id', $this->user_group_id)->whereNull('management_group_id')->first();
    }

    /**
     * @param  Builder<ManagementGroup>  $query
     * @return Builder<ManagementGroup>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'userGroup.users',
            fn (Builder $q) => $q->where('users.id', $user->id)
        );
    }
}
