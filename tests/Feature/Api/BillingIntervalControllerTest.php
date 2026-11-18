<?php

namespace Tests\Feature\Api;

use App\Models\BillingInterval;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class BillingIntervalControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    /**
     * Verwaltungsgruppe mit genau einem Intervall 2026 (das automatisch erzeugte
     * wird durch ein festes ersetzt, damit der Test nicht vom heutigen Jahr abhängt).
     */
    private function groupWith2026(User $user): ManagementGroup
    {
        $managementGroup = $this->makeManagementGroup($user);
        $managementGroup->billingIntervals()->delete();
        $managementGroup->billingIntervals()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        return $managementGroup;
    }

    private function store(ManagementGroup $managementGroup, string $start, ?string $end = null)
    {
        return $this->postJson(route('api.billing-intervals.store'), [
            'management_group_id' => $managementGroup->id, 'start_date' => $start, 'end_date' => $end,
        ]);
    }

    public function test_intervals_can_be_listed_appended_and_prepended(): void
    {
        $managementGroup = $this->groupWith2026($this->apiUser());

        $this->store($managementGroup, '2027-01-01')->assertCreated()->assertJsonPath('end_date', '2027-12-31T00:00:00.000000Z');
        $this->store($managementGroup, '2025-01-01', '2025-12-31')->assertCreated();

        $this->getJson(route('api.billing-intervals.index', $managementGroup))->assertOk()->assertJsonCount(3)
            ->assertJsonPath('0.start_date', '2025-01-01T00:00:00.000000Z');
    }

    public function test_gaps_overlong_intervals_and_starts_before_billing_start_are_rejected(): void
    {
        $managementGroup = $this->groupWith2026($this->apiUser());

        $this->store($managementGroup, '2028-01-01', '2028-12-31')->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->store($managementGroup, '2027-01-01', '2028-06-30')->assertUnprocessable()->assertJsonValidationErrors('end_date');

        $managementGroup->update(['billing_start' => '2026-01-01']);
        $this->store($managementGroup, '2025-01-01', '2025-12-31')->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_the_first_interval_of_an_empty_group_may_start_anywhere(): void
    {
        $managementGroup = $this->makeManagementGroup($this->apiUser());
        $managementGroup->billingIntervals()->delete();

        $this->store($managementGroup, '2020-03-01', '2021-02-28')->assertCreated();
    }

    public function test_an_unused_first_or_last_interval_can_be_deleted(): void
    {
        $managementGroup = $this->groupWith2026($this->apiUser());
        $this->store($managementGroup, '2027-01-01')->assertCreated();
        $this->store($managementGroup, '2028-01-01')->assertCreated();
        [$first, $middle, $last] = $managementGroup->billingIntervals()->orderBy('start_date')->get();

        $this->deleteJson(route('api.billing-intervals.destroy', $middle))->assertUnprocessable();
        $this->deleteJson(route('api.billing-intervals.destroy', $last))->assertNoContent();
        $this->deleteJson(route('api.billing-intervals.destroy', $first))->assertNoContent();
        $this->assertSame([$middle->id], $managementGroup->billingIntervals()->pluck('id')->all());
    }

    public function test_an_interval_in_use_by_costs_cannot_be_deleted(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = $this->apiUser();
        $managementGroup = $this->groupWith2026($user);
        $interval = $managementGroup->billingIntervals()->first();
        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer($user)->id, 'amount' => 10, 'invoice_date' => '2026-01-01',
            'periodicity' => 'once', 'costable_type' => 'management_group', 'costable_id' => $managementGroup->id,
        ]);
        $invoice->costs()->create([
            'costable_type' => 'management_group', 'costable_id' => $managementGroup->id, 'billing_interval_id' => $interval->id,
            'cost_category_id' => CostCategory::first()->id, 'amount' => 10, 'period_date' => '2026-01-01',
        ]);

        $this->deleteJson(route('api.billing-intervals.destroy', $interval))->assertUnprocessable()
            ->assertJsonValidationErrors('billing_interval');
        $this->assertNotNull(BillingInterval::find($interval->id));
    }

    public function test_foreign_groups_are_inaccessible(): void
    {
        $managementGroup = $this->groupWith2026(User::factory()->create());
        $this->apiUser();

        $this->getJson(route('api.billing-intervals.index', $managementGroup))->assertForbidden();
        $this->store($managementGroup, '2027-01-01')->assertForbidden();
        $this->deleteJson(route('api.billing-intervals.destroy', $managementGroup->billingIntervals()->first()))->assertForbidden();
    }
}
