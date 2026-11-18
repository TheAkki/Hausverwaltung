<?php

namespace App\Models\Concerns;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared behavior for the app's contact-detail models (Tenant, InvoiceIssuer,
 * Landlord): a "Vorname + Nachname/Firma" display name, the owning Benutzergruppe
 * relation, and account-scoped visibility. The surname/Firma column differs per
 * model (Tenant: last_name, InvoiceIssuer/Landlord: name), so it's read through
 * surnameAttribute(), which a class using this trait can override.
 */
trait HasContactDetails
{
    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->{$this->surnameAttribute()}}");
    }

    /**
     * "Straße, PLZ Ort", omitting a missing part entirely (and its separator) instead
     * of leaving a stray leading/trailing comma when e.g. only the street is filled in.
     */
    public function fullAddress(): string
    {
        $postalCodeAndCity = trim("{$this->postal_code} {$this->city}");

        return implode(', ', array_filter([$this->street, $postalCodeAndCity], fn ($part) => trim((string) $part) !== ''));
    }

    protected function surnameAttribute(): string
    {
        return 'name';
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'user_group_id');
    }

    /**
     * Scopes the model to those owned by a group the given user belongs to.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('user_group_id', $user->groups->pluck('id'));
    }
}
