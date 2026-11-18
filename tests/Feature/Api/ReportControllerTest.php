<?php

namespace Tests\Feature\Api;

use App\Models\CostCategory;
use App\Models\Lease;
use App\Models\User;
use Database\Seeders\BillingTemplateSeeder;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class ReportControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private const YEAR = 2026;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CostCategorySeeder::class);
        $this->seed(BillingTemplateSeeder::class);
    }

    /**
     * @return array{0: Lease, 1: array<int, mixed>}
     */
    private function leaseWithStatementRoute(User $user): array
    {
        $lease = $this->makeLease($user);
        $managementUnit = $lease->rentalUnit->managementUnit;

        return [$lease, [$managementUnit, $managementUnit->managementGroup->billingIntervals()->first()]];
    }

    public function test_the_financial_overview_lists_income_and_expenses_of_the_year(): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);
        $managementUnit = $lease->rentalUnit->managementUnit;
        $managementGroup = $managementUnit->managementGroup;
        $billingIntervalId = $managementGroup->billingIntervals()->first()->id;
        $categoryId = CostCategory::first()->id;
        foreach ([$managementGroup, $managementUnit, $lease->rentalUnit] as $costable) {
            $costable->costs()->create([
                'billing_interval_id' => $billingIntervalId, 'cost_category_id' => $categoryId,
                'amount' => 10, 'period_date' => '2026-03-01',
            ]);
        }

        $response = $this->getJson(route('api.reports.financial-overview', ['managementGroup' => $managementGroup, 'year' => self::YEAR]));

        $response->assertOk()->assertJsonPath('year', self::YEAR)->assertJsonStructure(['income', 'expenses']);
        $this->assertNotEmpty($response->json('income'));
        $this->assertNotEmpty($response->json('expenses'));
    }

    public function test_the_financial_overview_defaults_to_the_current_year(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->getJson(route('api.reports.financial-overview', $managementGroup))->assertOk()->assertJsonPath('year', now()->year);
    }

    public function test_a_foreign_financial_overview_is_forbidden(): void
    {
        $managementGroup = $this->makeManagementGroup(User::factory()->create());
        $this->apiUser();

        $this->getJson(route('api.reports.financial-overview', $managementGroup))->assertForbidden();
    }

    public function test_the_combined_statement_is_returned_as_pdf(): void
    {
        [, $route] = $this->leaseWithStatementRoute($this->apiUser());

        $this->get(route('api.reports.statement-pdf', $route))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_single_lease_statement_is_returned_as_pdf(): void
    {
        [$lease, $route] = $this->leaseWithStatementRoute($this->apiUser());

        $response = $this->get(route('api.reports.lease-statement-pdf', [...$route, $lease]));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('Mustermann', $response->headers->get('content-disposition'));
    }

    public function test_all_statements_are_returned_as_zip(): void
    {
        [, $route] = $this->leaseWithStatementRoute($this->apiUser());

        $response = $this->get(route('api.reports.statement-zip', $route));

        $response->assertOk();
        $this->assertStringContainsString('.zip', $response->headers->get('content-disposition'));
    }

    public function test_a_foreign_statement_is_forbidden(): void
    {
        [, $route] = $this->leaseWithStatementRoute(User::factory()->create());
        $this->apiUser();

        $this->getJson(route('api.reports.statement-pdf', $route))->assertForbidden();
    }
}
