<?php

namespace App\Services\GroupOwnership;

use App\Exceptions\GroupMismatchException;
use App\Models\Account;
use App\Models\BillingInterval;
use App\Models\BillingTemplate;
use App\Models\InvoiceIssuerAccount;
use App\Models\ManagementGroup;

/**
 * Verweise innerhalb einer Verwaltungsgruppe bleiben in dieser Verwaltungsgruppe:
 * Abrechnungsintervall und Konto einer Kostenzeile bzw. Mietzahlung gehören zur
 * Verwaltungsgruppe ihrer Zuordnung, ein Kundenaccount zum Rechnungssteller der
 * Rechnung und die Standardvorlage zur Benutzergruppe der Verwaltungsgruppe (oder ist
 * eine eingebaute Vorlage). Sonst ließe sich über eine fremde Id in die Daten einer
 * anderen Gruppe schreiben oder aus ihnen lesen.
 *
 * Ein Verstoß ist eine GroupMismatchException (in bootstrap/app.php ein
 * Validierungsfehler am genannten Feld).
 */
final class ReferenceIntegrity
{
    public const FIELD_INTERVAL = 'billing_interval_id';

    public const FIELD_ACCOUNT = 'account_id';

    public const FIELD_ISSUER_ACCOUNT = 'invoice_issuer_account_id';

    public const FIELD_TEMPLATE = 'default_billing_template_id';

    public static function assertIntervalBelongsTo(int|string|null $intervalId, ?ManagementGroup $managementGroup, string $field = self::FIELD_INTERVAL): void
    {
        if ($intervalId === null || $managementGroup === null) {
            return;
        }

        if (BillingInterval::whereKey($intervalId)->value('management_group_id') !== $managementGroup->id) {
            throw new GroupMismatchException($field, __('Das Abrechnungsintervall gehört nicht zur Verwaltungsgruppe „:management_group“.', [
                'management_group' => $managementGroup->description,
            ]));
        }
    }

    public static function assertAccountBelongsTo(int|string|null $accountId, ?ManagementGroup $managementGroup, string $field = self::FIELD_ACCOUNT): void
    {
        if ($accountId === null || $managementGroup === null) {
            return;
        }

        if (Account::whereKey($accountId)->value('management_group_id') !== $managementGroup->id) {
            throw new GroupMismatchException($field, __('Das Konto gehört nicht zur Verwaltungsgruppe „:management_group“.', [
                'management_group' => $managementGroup->description,
            ]));
        }
    }

    public static function assertIssuerAccountBelongsTo(int|string|null $issuerAccountId, int|string|null $issuerId, string $field = self::FIELD_ISSUER_ACCOUNT): void
    {
        if ($issuerAccountId === null || $issuerId === null) {
            return;
        }

        if (InvoiceIssuerAccount::whereKey($issuerAccountId)->value('invoice_issuer_id') !== (int) $issuerId) {
            throw new GroupMismatchException($field, __('Der Kundenaccount gehört nicht zum gewählten Rechnungssteller.'));
        }
    }

    /**
     * Eine eingebaute Vorlage (ohne Gruppe) passt überall, eine eigene nur in ihrer Gruppe.
     */
    public static function templateFits(BillingTemplate $template, int $userGroupId): bool
    {
        return $template->user_group_id === null || $template->user_group_id === $userGroupId;
    }

    public static function assertTemplateFits(int|string|null $templateId, int $userGroupId, string $field = self::FIELD_TEMPLATE): void
    {
        $template = $templateId === null ? null : BillingTemplate::find($templateId);

        if ($template !== null && ! self::templateFits($template, $userGroupId)) {
            throw new GroupMismatchException($field, __('Die Vorlage „:template“ gehört zu einer anderen Gruppe als die Verwaltungsgruppe.', [
                'template' => $template->name,
            ]));
        }
    }
}
