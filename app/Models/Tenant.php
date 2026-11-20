<?php

namespace App\Models;

use App\Models\Concerns\HasContactDetails;
use App\Models\Pivots\LeaseTenant;
use App\Observers\TenantObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Represents a tenant (Mieter) with contact and bank details, belonging to many
 * Leases through a pivot table. Belongs to the Benutzergruppe (Account) that
 * created it, since Mieter are managed per Account rather than per Verwaltungsgruppe.
 */
#[Fillable(['user_group_id', 'salutation', 'last_name', 'first_name', 'company_name', 'street', 'postal_code', 'city', 'email', 'mobile_phone', 'iban', 'bic', 'notes'])]
#[ObservedBy(TenantObserver::class)]
class Tenant extends Model
{
    use HasContactDetails, HasFactory;

    /**
     * @return BelongsToMany<Lease, $this>
     */
    public function leases(): BelongsToMany
    {
        return $this->belongsToMany(Lease::class)->using(LeaseTenant::class)->withTimestamps();
    }

    protected function surnameAttribute(): string
    {
        return 'last_name';
    }
}
