<?php

namespace App\Models;

use App\Models\Concerns\HasContactDetails;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Vermieter (landlord), used on the Betriebskostenabrechnung as the sender/absender.
 * Each Account has one account-level Vermieter (user_group_id set); a Verwaltungsgruppe
 * may override it with its own (management_group_id set). See
 * ManagementGroup::effectiveLandlord().
 */
#[Fillable([
    'user_group_id', 'management_group_id', 'salutation', 'first_name', 'name', 'company_name',
    'street', 'postal_code', 'city', 'email', 'phone', 'mobile_phone', 'iban', 'bic', 'notes',
])]
class Landlord extends Model
{
    use HasContactDetails, HasFactory;

    /**
     * @return BelongsTo<ManagementGroup, $this>
     */
    public function managementGroup(): BelongsTo
    {
        return $this->belongsTo(ManagementGroup::class);
    }
}
