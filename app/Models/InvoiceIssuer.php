<?php

namespace App\Models;

use App\Models\Concerns\HasContactDetails;
use App\Observers\InvoiceIssuerObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents the issuer (supplier/provider) of invoices, holding contact details and
 * having many Invoices. Belongs to the Benutzergruppe (Account) that created it, since
 * Rechnungssteller are managed per Account rather than per Verwaltungsgruppe.
 */
#[Fillable([
    'user_group_id', 'name', 'first_name', 'salutation', 'company_name', 'street', 'postal_code', 'city',
    'email', 'phone', 'mobile_phone', 'iban', 'bic', 'comment',
])]
#[ObservedBy(InvoiceIssuerObserver::class)]
class InvoiceIssuer extends Model
{
    use HasContactDetails, HasFactory;

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<InvoiceIssuerAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(InvoiceIssuerAccount::class);
    }
}
