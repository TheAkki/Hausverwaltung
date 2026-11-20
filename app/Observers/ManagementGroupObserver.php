<?php

namespace App\Observers;

use App\Models\BillingInterval;
use App\Models\ManagementGroup;
use App\Services\GroupOwnership\GroupOwnership;
use App\Services\GroupOwnership\ReferenceIntegrity;
use Carbon\Carbon;

/**
 * Reacts to ManagementGroup "created" events by seeding the first one-year
 * BillingInterval starting from the group's billing_start (or January 1st).
 */
class ManagementGroupObserver
{
    /**
     * Eine Verwaltungsgruppe wechselt die Benutzergruppe nur zusammen mit ihren
     * Stammdaten (ManagementGroupTransfer); ein bloßes Umsetzen von user_group_id
     * wird abgelehnt, wenn sie dann Stammdaten einer fremden Gruppe verwendete.
     */
    public function updating(ManagementGroup $managementGroup): void
    {
        if (GroupOwnership::isSuspended() || ! $managementGroup->isDirty('user_group_id')) {
            return;
        }

        GroupOwnership::assertNoViolations($managementGroup, (int) $managementGroup->user_group_id);
    }

    /**
     * Die Standardvorlage ist eine eingebaute Vorlage oder gehört zur Benutzergruppe der
     * Verwaltungsgruppe (ReferenceIntegrity) – sonst sähen deren Mitglieder in der
     * Abrechnung Inhalt und Kopfbild einer fremden Gruppe.
     */
    public function saving(ManagementGroup $managementGroup): void
    {
        if (GroupOwnership::isSuspended() || ! $managementGroup->isDirty(['default_billing_template_id', 'user_group_id'])) {
            return;
        }

        ReferenceIntegrity::assertTemplateFits($managementGroup->default_billing_template_id, (int) $managementGroup->user_group_id);
    }

    /**
     * Handle the ManagementGroup "created" event: seed the first billing interval.
     */
    public function created(ManagementGroup $managementGroup): void
    {
        $start = $managementGroup->billing_start
            ? Carbon::parse($managementGroup->billing_start)
            : Carbon::createFromDate(now()->year, 1, 1);

        $end = (clone $start)->addYear()->subDay();

        BillingInterval::create([
            'management_group_id' => $managementGroup->id,
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }
}
