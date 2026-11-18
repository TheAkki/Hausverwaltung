<?php

namespace Tests\Feature;

use App\Models\ManagementGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers manual creation of Abrechnungsintervalle: gap-free sequencing, the one-year
 * maximum length, and group-visibility authorization.
 */
class BillingIntervalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagementGroup(User $user): ManagementGroup
    {
        $group = $user->groups()->first();

        return ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
    }

    public function test_a_billing_interval_seamlessly_following_the_latest_one_can_be_created(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $existing = $managementGroup->billingIntervals()->first();
        $existing->update(['end_date' => '2026-12-31']);

        $response = $this->actingAs($user)->post(route('billing-intervals.store'), [
            'management_group_id' => $managementGroup->id,
            'start_date' => '2027-01-01',
        ]);

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $newInterval = $managementGroup->billingIntervals()->orderBy('start_date', 'desc')->first();
        // end_date defaults to Dec 31 of the start year.
        $this->assertSame('2027-12-31', $newInterval->end_date->toDateString());
    }

    public function test_a_billing_interval_that_would_leave_a_gap_is_rejected(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $existing = $managementGroup->billingIntervals()->first();
        $existing->update(['end_date' => '2026-12-31']);

        $response = $this->actingAs($user)->post(route('billing-intervals.store'), [
            'management_group_id' => $managementGroup->id,
            'start_date' => '2027-02-01',
        ]);

        $response->assertSessionHasErrors('start_date');
        $this->assertSame(1, $managementGroup->billingIntervals()->count());
    }

    public function test_a_billing_interval_seamlessly_preceding_the_earliest_one_can_be_created(): void
    {
        // Regression: a group auto-gets its first Abrechnungsintervall for the current
        // year (e.g. 2026). Backfilling an earlier year (e.g. 2025) must be possible –
        // the "no gap" check must not only allow appending after the latest interval,
        // but also prepending seamlessly before the earliest one.
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $existing = $managementGroup->billingIntervals()->first();
        $existing->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $response = $this->actingAs($user)->post(route('billing-intervals.store'), [
            'management_group_id' => $managementGroup->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $this->assertSame(2, $managementGroup->billingIntervals()->count());
        $earliest = $managementGroup->billingIntervals()->orderBy('start_date')->first();
        $this->assertSame('2025-01-01', $earliest->start_date->toDateString());
        $this->assertSame('2025-12-31', $earliest->end_date->toDateString());
    }

    public function test_a_billing_interval_that_would_leave_a_gap_before_the_earliest_one_is_rejected(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $existing = $managementGroup->billingIntervals()->first();
        $existing->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $response = $this->actingAs($user)->post(route('billing-intervals.store'), [
            'management_group_id' => $managementGroup->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-11-30',
        ]);

        $response->assertSessionHasErrors('start_date');
        $this->assertSame(1, $managementGroup->billingIntervals()->count());
    }

    public function test_a_billing_interval_starting_before_the_groups_own_billing_start_is_rejected(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $managementGroup->update(['billing_start' => '2025-06-01']);
        $existing = $managementGroup->billingIntervals()->first();
        $existing->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        // Lückenlos vor dem frühesten Intervall und innerhalb eines Jahres, aber vor dem
        // Abrechnungsbeginn der Verwaltungsgruppe – darf trotz Lückenlosigkeit nicht
        // erlaubt werden.
        $response = $this->actingAs($user)->post(route('billing-intervals.store'), [
            'management_group_id' => $managementGroup->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $response->assertSessionHasErrors('start_date');
        $this->assertSame(1, $managementGroup->billingIntervals()->count());
    }

    public function test_a_billing_interval_starting_exactly_on_the_groups_billing_start_is_accepted(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $managementGroup->update(['billing_start' => '2025-05-01']);
        $existing = $managementGroup->billingIntervals()->first();
        $existing->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $response = $this->actingAs($user)->post(route('billing-intervals.store'), [
            'management_group_id' => $managementGroup->id,
            'start_date' => '2025-05-01',
            'end_date' => '2025-12-31',
        ]);

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $this->assertSame(2, $managementGroup->billingIntervals()->count());
    }

    public function test_a_billing_interval_longer_than_one_year_is_rejected(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $existing = $managementGroup->billingIntervals()->first();
        $existing->update(['end_date' => '2026-12-31']);

        $response = $this->actingAs($user)->post(route('billing-intervals.store'), [
            'management_group_id' => $managementGroup->id,
            'start_date' => '2027-01-01',
            'end_date' => '2028-06-30',
        ]);

        $response->assertSessionHasErrors('end_date');
        $this->assertSame(1, $managementGroup->billingIntervals()->count());
    }

    public function test_a_user_outside_the_group_cannot_create_a_billing_interval(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);

        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->post(route('billing-intervals.store'), [
            'management_group_id' => $managementGroup->id,
            'start_date' => '2027-01-01',
        ]);

        $response->assertForbidden();
    }

    public function test_the_create_form_requires_view_access_to_the_management_group(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);

        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('billing-intervals.create', ['management_group_id' => $managementGroup->id]));

        $response->assertForbidden();
    }
}
