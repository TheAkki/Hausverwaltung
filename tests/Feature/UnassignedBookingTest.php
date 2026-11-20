<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\ManagementGroup;
use App\Models\User;
use App\Services\UnassignedBookingFinder;
use Carbon\Carbon;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * "Detailbild nicht zugeordnete Buchungen": Termine bis heute, deren Kostenzeilen nicht
 * den Sollbetrag ergeben – außer bei deaktiviertem Betragsdeckel.
 */
class UnassignedBookingTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private const TODAY = '2026-03-10';

    private User $user;

    private ManagementGroup $managementGroup;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY);
        $this->seed(CostCategorySeeder::class);
        $this->user = User::factory()->create();
        $this->managementGroup = $this->makeManagementGroup($this->user);
        $this->category = CostCategory::whereNull('user_group_id')->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, float>  $costs  period_date => Betrag
     */
    private function invoice(array $attributes, array $costs = [], ?ManagementGroup $managementGroup = null, ?User $owner = null): Invoice
    {
        $managementGroup ??= $this->managementGroup;
        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer($owner ?? $this->user)->id, 'amount' => 100, 'invoice_date' => '2026-01-05',
            'periodicity' => 'once', 'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            ...$attributes,
        ]);

        foreach ($costs as $date => $amount) {
            $invoice->costs()->create([
                'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
                'billing_interval_id' => $managementGroup->billingIntervals()->value('id'), 'cost_category_id' => $this->category,
                'amount' => $amount, 'period_date' => $date,
            ]);
        }

        return $invoice;
    }

    /**
     * @return list<array{0: string, 1: string, 2: float}>
     */
    private function found(): array
    {
        return app(UnassignedBookingFinder::class)->find($this->user)
            ->map(fn (array $row) => [$row['invoice']->description, $row['date'], $row['difference']])->all();
    }

    public function test_a_once_invoice_is_listed_when_its_costs_do_not_add_up(): void
    {
        $this->invoice(['description' => 'Teilweise'], ['2026-01-05' => 60]);
        $this->invoice(['description' => 'Ohne Kosten']);
        $this->invoice(['description' => 'Vollständig'], ['2026-01-05' => 60, '2026-01-06' => 40]);
        $this->invoice(['description' => 'Skonto', 'effective_amount' => 97], ['2026-01-05' => 97]);
        $this->invoice(['description' => 'Darf abweichen', 'disable_amount_cap' => true], ['2026-01-05' => 10]);
        $this->invoice(['description' => 'Zukunft', 'invoice_date' => '2026-04-01']);
        $this->invoice(['description' => 'Fremd'], [], $this->makeManagementGroup($stranger = User::factory()->create()), $stranger);

        $this->assertSame([['Teilweise', '2026-01-05', 40.0], ['Ohne Kosten', '2026-01-05', 100.0]], $this->found());
    }

    public function test_a_cost_without_period_date_counts_for_a_once_invoice_only(): void
    {
        $once = $this->invoice(['description' => 'Einmalig'], ['2026-01-05' => 60]);
        $monthly = $this->invoice(['description' => 'Monatlich', 'periodicity' => 'monthly', 'recurrence_start' => '2026-03-05'], ['2026-03-05' => 60]);
        foreach ([$once, $monthly] as $invoice) {
            $invoice->costs()->create([
                'costable_type' => $this->managementGroup->getMorphClass(), 'costable_id' => $this->managementGroup->id,
                'billing_interval_id' => $this->managementGroup->billingIntervals()->value('id'), 'cost_category_id' => $this->category,
                'amount' => 40, 'period_date' => null,
            ]);
        }

        $this->assertSame([['Monatlich', '2026-03-05', 40.0]], $this->found());
    }

    public function test_a_manual_invoice_is_checked_per_past_occurrence(): void
    {
        $invoice = $this->invoice(['description' => 'Händisch', 'periodicity' => 'manual'], ['2026-01-10' => 30, '2026-02-10' => 50]);
        $invoice->occurrences()->createMany([
            ['occurrence_date' => '2026-01-10', 'amount' => 30],
            ['occurrence_date' => '2026-02-10', 'amount' => 45],
            ['occurrence_date' => '2026-04-10', 'amount' => 99],
        ]);

        $this->assertSame([['Händisch', '2026-02-10', -5.0]], $this->found());
    }

    public function test_a_recurring_invoice_is_checked_per_term_until_today_with_its_segments(): void
    {
        $this->invoice([
            'description' => 'Minijob', 'periodicity' => 'monthly', 'recurrence_start' => '2026-01-05', 'recurrence_end' => null,
            'amount_segments' => [
                ['start_date' => '2026-01-05', 'end_date' => '2026-01-31', 'amount' => 100],
                ['start_date' => '2026-02-01', 'end_date' => null, 'amount' => 120],
            ],
        ], ['2026-01-05' => 100, '2026-02-05' => 120, '2026-03-05' => 100, '2026-04-05' => 1]);
        $this->invoice([
            'description' => 'Beendet', 'periodicity' => 'monthly', 'recurrence_start' => '2025-11-05', 'recurrence_end' => '2025-12-05',
        ], ['2025-11-05' => 100]);
        $this->invoice(['description' => 'Noch nicht begonnen', 'periodicity' => 'monthly', 'recurrence_start' => '2026-05-01']);
        $this->invoice(['description' => 'Ohne Beginn', 'periodicity' => 'monthly', 'recurrence_start' => null], ['2026-01-05' => 100]);

        $this->assertSame([['Beendet', '2025-12-05', 100.0], ['Minijob', '2026-03-05', 20.0]], $this->found());
    }

    public function test_the_page_lists_the_rows_with_a_link_to_the_invoice(): void
    {
        $invoice = $this->invoice(['description' => 'Teilweise'], ['2026-01-05' => 60]);

        $this->actingAs($this->user)->get(route('unassigned-bookings.index'))
            ->assertOk()->assertSee('data-unassigned-bookings', false)
            ->assertSee('05.01.2026')->assertSee('40,00 €')->assertSee(route('invoices.show', $invoice), false);

        $invoice->costs()->first()->update(['amount' => 100]);
        $this->actingAs($this->user)->get(route('unassigned-bookings.index'))
            ->assertOk()->assertSee('Alle Buchungen sind vollständig zugeordnet.');
        $this->actingAs($this->user)->get(route('dashboard'))->assertSee(route('unassigned-bookings.index'), false);
    }
}
