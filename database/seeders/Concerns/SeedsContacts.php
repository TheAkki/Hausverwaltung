<?php

namespace Database\Seeders\Concerns;

use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use App\Models\Landlord;
use App\Models\Tenant;

/**
 * Seeds the app's three near-identical Kontaktdaten entities (Mieter, Rechnungssteller,
 * Vermieter) plus a Rechnungssteller's Kundenaccounts, sharing a common field-extraction
 * helper since the underlying YAML blocks all carry the same
 * Firma/Vorname/Name/Straße/PLZ/Ort/Anrede/Email/Telefon/Mobilfunk/IBAN/BIC/Notiz shape.
 */
trait SeedsContacts
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function seedTenant(array $data, int $userGroupId): Tenant
    {
        $fields = $this->extractContactFields($data);

        if ($fields['name'] === '' || $fields['street'] === '' || $fields['city'] === '') {
            throw new \RuntimeException(
                'Ein Mieter-Eintrag hat keinen Namen, keine Straße oder keinen Ort (alle drei sind Pflichtangaben).'
            );
        }

        return Tenant::firstOrCreate([
            'user_group_id' => $userGroupId,
            'first_name' => $fields['firstName'] !== '' ? $fields['firstName'] : null,
            'last_name' => $fields['name'],
            'street' => $fields['street'],
            'postal_code' => $fields['postalCode'],
            'city' => $fields['city'],
        ], [
            'salutation' => $fields['salutation'],
            'company_name' => $fields['firma'] !== '' ? $fields['firma'] : null,
            'email' => $fields['email'],
            'mobile_phone' => $fields['mobilePhone'],
            'iban' => $fields['iban'],
            'bic' => $fields['bic'],
            'notes' => $fields['notes'],
        ]);
    }

    /**
     * Seeds a Rechnungssteller. Unlike Tenant/Mieter, either a personal Name or a
     * Firma alone is enough to identify one (the real seeddata.yaml only ever
     * supplies Firma), so the display "name" falls back to Firma when no personal
     * name is given. Straße may legitimately be an empty string.
     *
     * @param  array<string, mixed>  $data
     */
    private function seedInvoiceIssuer(array $data, int $userGroupId): InvoiceIssuer
    {
        $fields = $this->extractContactFields($data);
        $name = $fields['name'] !== '' ? $fields['name'] : $fields['firma'];

        if ($name === '') {
            throw new \RuntimeException('Beim Rechnungssteller muss entweder Firma oder Name angegeben sein.');
        }

        return InvoiceIssuer::firstOrCreate([
            'user_group_id' => $userGroupId,
            'name' => $name,
            'postal_code' => $fields['postalCode'],
            'city' => $fields['city'],
        ], [
            'first_name' => $fields['firstName'] !== '' ? $fields['firstName'] : null,
            'salutation' => $fields['salutation'],
            'company_name' => $fields['firma'] !== '' ? $fields['firma'] : null,
            'street' => $fields['street'],
            'email' => $fields['email'],
            'phone' => $fields['phone'],
            'mobile_phone' => $fields['mobilePhone'],
            'iban' => $fields['iban'],
            'bic' => $fields['bic'],
            'comment' => $fields['notes'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, InvoiceIssuerAccount> the created Kundenaccounts, keyed by their YAML suffix
     */
    private function seedInvoiceIssuerAccounts(array $data, InvoiceIssuer $issuer): array
    {
        $accountsByKey = [];

        foreach ($this->entriesMatchingWithKeys($data, 'Kundenaccount') as $key => $accountData) {
            $customerNumber = trim((string) ($accountData['Kundenummer'] ?? ''));

            if ($customerNumber === '') {
                throw new \RuntimeException("Kundenaccount-{$key} des Rechnungsstellers \"{$issuer->name}\" hat keine Kundenummer.");
            }

            $accountsByKey[$key] = InvoiceIssuerAccount::firstOrCreate([
                'invoice_issuer_id' => $issuer->id,
                'customer_number' => $customerNumber,
            ], [
                'description' => $accountData['Beschreibung'] ?? null,
                'direct_debit' => (bool) ($accountData['Lastschrift'] ?? false),
                'notes' => $accountData['Notizen'] ?? null,
            ]);
        }

        return $accountsByKey;
    }

    /**
     * Seeds a Vermieter (landlord) either at account level (userGroupId set) or as a
     * Verwaltungsgruppen-override (managementGroupId set). Idempotent per owner:
     * updateOrCreate keyed on the owning group so re-seeding updates in place. Needs at
     * least a Name (or Firma) – the caller only calls this once a "Vermieter-*" block
     * was actually found, so one without either is an incomplete entry, not an absent
     * (optional) one.
     *
     * @param  array<string, mixed>  $data
     */
    private function seedLandlord(array $data, ?int $userGroupId, ?int $managementGroupId): void
    {
        $fields = $this->extractContactFields($data);
        $displayName = $fields['name'] !== '' ? $fields['name'] : $fields['firma'];

        if ($displayName === '') {
            throw new \RuntimeException('Beim Vermieter muss entweder Firma oder Name angegeben sein.');
        }

        Landlord::updateOrCreate(
            ['user_group_id' => $userGroupId, 'management_group_id' => $managementGroupId],
            [
                'salutation' => $fields['salutation'],
                'first_name' => $fields['firstName'] !== '' ? $fields['firstName'] : null,
                'name' => $displayName,
                'company_name' => $fields['firma'] !== '' ? $fields['firma'] : null,
                'street' => $fields['street'] !== '' ? $fields['street'] : null,
                'postal_code' => $fields['postalCode'] !== '' ? $fields['postalCode'] : null,
                'city' => $fields['city'] !== '' ? $fields['city'] : null,
                'email' => $fields['email'],
                'phone' => $fields['phone'],
                'mobile_phone' => $fields['mobilePhone'],
                'iban' => $fields['iban'],
                'bic' => $fields['bic'],
                'notes' => $fields['notes'],
            ]
        );
    }

    /**
     * Extracts the common Kontaktdaten fields (Firma/Vorname/Name/Straße/PLZ/Ort/Anrede/
     * Email/Telefon/Mobilfunk/IBAN/BIC/Notiz) shared by the Mieter-, Rechnungssteller-
     * and Vermieter-YAML-Blöcke. Each caller still applies its own required-ness rules
     * and target column names (e.g. Tenant.last_name vs. InvoiceIssuer/Landlord.name,
     * InvoiceIssuer.comment vs. notes elsewhere).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractContactFields(array $data): array
    {
        return [
            'firma' => trim((string) ($data['Firma'] ?? '')),
            'firstName' => trim((string) ($data['Vorname'] ?? '')),
            'name' => trim((string) ($data['Name'] ?? '')),
            'street' => trim((string) ($data['Straße'] ?? '')),
            'postalCode' => (string) ($data['PLZ'] ?? ''),
            'city' => trim((string) ($data['Ort'] ?? '')),
            'salutation' => $data['Anrede'] ?? null,
            'email' => $data['Email'] ?? null,
            'phone' => $data['Telefon'] ?? null,
            'mobilePhone' => $data['Mobilfunk'] ?? null,
            'iban' => $data['IBAN'] ?? null,
            'bic' => $data['BIC'] ?? null,
            'notes' => $data['Notiz'] ?? null,
        ];
    }
}
