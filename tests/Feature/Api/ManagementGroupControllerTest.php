<?php

namespace Tests\Feature\Api;

use App\Models\BillingTemplate;
use App\Models\ManagementGroup;
use App\Models\User;
use App\Services\InvoiceSeries\ForecastRefresher;
use Database\Seeders\BillingTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class ManagementGroupControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    public function test_the_index_only_lists_visible_management_groups(): void
    {
        $user = $this->apiUser();
        $this->makeManagementUnit($user);
        $this->makeManagementGroup(User::factory()->create());

        $this->getJson(route('api.management-groups.index'))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.management_units_count', 1);
    }

    public function test_a_management_group_is_created_with_the_default_forecast_mode(): void
    {
        $user = $this->apiUser();

        $response = $this->postJson(route('api.management-groups.store'), [
            'user_group_id' => $this->groupOf($user)->id, 'description' => 'Neu', 'kontosystem_enabled' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('variable_cost_forecast_mode', ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING)
            ->assertJsonPath('kontosystem_enabled', true);
    }

    public function test_a_management_group_cannot_be_created_for_a_foreign_group(): void
    {
        $this->apiUser();

        $this->postJson(route('api.management-groups.store'), [
            'user_group_id' => $this->groupOf(User::factory()->create())->id,
        ])->assertForbidden();
    }

    public function test_an_unknown_forecast_mode_is_rejected(): void
    {
        $user = $this->apiUser();

        $this->postJson(route('api.management-groups.store'), [
            'user_group_id' => $this->groupOf($user)->id, 'variable_cost_forecast_mode' => 'raten',
        ])->assertUnprocessable()->assertJsonValidationErrors('variable_cost_forecast_mode');
    }

    public function test_a_management_group_can_be_shown(): void
    {
        $user = $this->apiUser();
        $managementUnit = $this->makeManagementUnit($user);

        $this->getJson(route('api.management-groups.show', $managementUnit->managementGroup))
            ->assertOk()->assertJsonPath('management_units.0.id', $managementUnit->id);
    }

    public function test_a_foreign_management_group_cannot_be_shown(): void
    {
        $managementGroup = $this->makeManagementGroup(User::factory()->create());
        $this->apiUser();

        $this->getJson(route('api.management-groups.show', $managementGroup))->assertForbidden();
    }

    public function test_updating_without_a_mode_change_does_not_refresh_the_forecasts(): void
    {
        $this->seed(BillingTemplateSeeder::class);
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $this->mock(ForecastRefresher::class, fn (MockInterface $mock) => $mock->shouldNotReceive('refreshManagementGroup'));
        $template = BillingTemplate::first();

        $response = $this->putJson(route('api.management-groups.update', $managementGroup), [
            'user_group_id' => $this->groupOf($user)->id, 'description' => 'Geändert',
            'default_billing_template_id' => $template->id,
        ]);

        $response->assertOk()->assertJsonPath('description', 'Geändert')
            ->assertJsonPath('default_billing_template_id', $template->id)
            ->assertJsonPath('variable_cost_forecast_mode', ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING);
    }

    public function test_changing_the_forecast_mode_refreshes_the_forecasts(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $this->mock(ForecastRefresher::class, fn (MockInterface $mock) => $mock->shouldReceive('refreshManagementGroup')->once());

        $this->putJson(route('api.management-groups.update', $managementGroup), [
            'user_group_id' => $this->groupOf($user)->id,
            'variable_cost_forecast_mode' => ManagementGroup::VARIABLE_FORECAST_NONE,
        ])->assertOk()->assertJsonPath('variable_cost_forecast_mode', ManagementGroup::VARIABLE_FORECAST_NONE);
    }

    public function test_an_unknown_billing_template_is_rejected(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->putJson(route('api.management-groups.update', $managementGroup), [
            'user_group_id' => $this->groupOf($user)->id, 'default_billing_template_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('default_billing_template_id');
    }

    public function test_a_management_group_cannot_be_moved_to_a_foreign_group(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->putJson(route('api.management-groups.update', $managementGroup), [
            'user_group_id' => $this->groupOf(User::factory()->create())->id,
        ])->assertForbidden();
    }

    public function test_a_management_group_can_be_deleted(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->deleteJson(route('api.management-groups.destroy', $managementGroup))->assertNoContent();
        $this->assertNull(ManagementGroup::find($managementGroup->id));
    }
}
