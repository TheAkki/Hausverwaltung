<?php

namespace App\Observers;

use App\Exceptions\GroupMismatchException;
use App\Models\BillingTemplate;
use App\Models\ManagementGroup;
use App\Services\GroupOwnership\GroupOwnership;

/**
 * Eine Vorlage wechselt die Gruppe nur, wenn keine Verwaltungsgruppe einer anderen
 * Gruppe sie als Standardvorlage verwendet (ReferenceIntegrity) – sonst sähen deren
 * Mitglieder in der Abrechnung eine Vorlage, die ihnen nicht gehört.
 */
class BillingTemplateObserver
{
    public function updating(BillingTemplate $billingTemplate): void
    {
        if (GroupOwnership::isSuspended() || ! $billingTemplate->isDirty('user_group_id')) {
            return;
        }

        $foreign = ManagementGroup::where('default_billing_template_id', $billingTemplate->id)
            ->where('user_group_id', '!=', $billingTemplate->user_group_id)
            ->orderBy('description')
            ->pluck('description');

        if ($billingTemplate->user_group_id !== null && $foreign->isNotEmpty()) {
            throw new GroupMismatchException(GroupOwnership::FIELD_GROUP, __(
                'Die Vorlage ist Standardvorlage von Verwaltungsgruppen einer anderen Gruppe: :list. Wähle dort zuerst eine andere Vorlage.',
                ['list' => $foreign->implode(', ')]
            ));
        }
    }
}
