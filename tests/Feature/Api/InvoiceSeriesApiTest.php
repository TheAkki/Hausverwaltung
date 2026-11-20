<?php

// KI-generiert

namespace Tests\Feature\Api;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\RentPayment;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * Rechnungen mit Aufteilung/Betragsintervallen, das Buchen von Abbuchungen und von
 * Mieteingängen sowie die exakten Suchfilter der Rechnungsliste.
 */
class InvoiceSeriesApiTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private User $user;

    private ManagementGroup $managementGroup;

    private CostCategory $interest;

    private CostCategory $repayment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
        $this->seed(CostCategorySeeder::class);
        $this->user = $this->apiUser();
        $this->managementGroup = $this->makeManagementGroup($this->user);
        $this->interest = CostCategory::where('name', 'Zinsen')->firstOrFail();
        $this->repayment = CostCategory::where('name', 'Tilgung')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function loan(array $overrides = []): array
    {
        return [
            'invoice_issuer_id' => $this->makeIssuer($this->user)->id,
            'invoice_date' => '2026-01-01',
            'periodicity' => 'monthly',
            'amount' => 500,
            'recurrence_start' => '2026-01-01',
            'recurrence_end' => '2026-04-01',
            'costable' => "management_group:{$this->managementGroup->id}",
            'allocations' => [
                ['cost_category_id' => $this->interest->id, 'amount' => 300],
                ['cost_category_id' => $this->repayment->id, 'amount' => 200],
            ],
            ...$overrides,
        ];
    }

    private function createLoan(array $overrides = []): Invoice
    {
        $id = $this->postJson(route('api.invoices.store'), $this->loan($overrides))->assertCreated()->json('id');

        return Invoice::findOrFail($id);
    }

    // KI-generiert
    public function test_an_invoice_with_allocations_gets_one_row_per_term_and_category(): void
    {
        $invoice = $this->createLoan();

        $this->assertSame(8, $invoice->costs()->count());
        $this->assertSame(1200.0, (float) $invoice->costs()->where('cost_category_id', $this->interest->id)->sum('amount'));
        $this->assertSame('2026-04-01', $invoice->recurrence_end->toDateString());
    }

    // KI-generiert
    public function test_a_single_allocation_without_amount_takes_the_whole_invoice_and_multiple_take_zero(): void
    {
        $single = $this->createLoan([
            'periodicity' => 'once', 'booking_date' => '2026-02-02', 'recurrence_start' => null, 'recurrence_end' => null,
            'allocations' => [['cost_category_id' => $this->interest->id]],
        ]);
        $open = $this->createLoan(['allocations' => [['cost_category_id' => $this->interest->id], ['cost_category_id' => $this->repayment->id]]]);

        $this->assertSame([500.0, '2026-02-02', '2026-02-02'], [(float) $single->costs()->sole()->amount, $single->costs()->sole()->period_date->toDateString(), $single->costs()->sole()->booking_date->toDateString()]);
        $this->assertSame(0.0, (float) $open->costs()->sum('amount'));
    }

    // KI-generiert
    public function test_allocations_must_add_up_unless_the_check_is_disabled(): void
    {
        $wrong = [['cost_category_id' => $this->interest->id, 'amount' => 300]];

        $this->postJson(route('api.invoices.store'), $this->loan(['allocations' => $wrong]))
            ->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $this->postJson(route('api.invoices.store'), $this->loan(['allocations' => $wrong, 'disable_amount_cap' => true]))->assertCreated();
    }

    // KI-generiert
    public function test_categories_and_intervals_of_other_groups_are_rejected(): void
    {
        $stranger = User::factory()->create();
        $foreign = CostCategory::create(['name' => 'Fremd', 'user_group_id' => $this->groupOf($stranger)->id, 'is_relevant_for_billing' => true]);
        $foreignInterval = $this->makeManagementGroup($stranger)->billingIntervals()->first();

        $this->postJson(route('api.invoices.store'), $this->loan(['allocations' => [['cost_category_id' => $foreign->id]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('allocations.0.cost_category_id');
        $this->postJson(route('api.invoices.store'), $this->loan(['billing_interval_id' => $foreignInterval->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('billing_interval_id');
    }

    // KI-generiert
    // KI-generiert: Eine eigene Kategorie der persönlichen Gruppe passt nicht in eine
    // Verwaltungsgruppe der gemeinsamen Gruppe (GroupOwnership) – deren Kopie schon.
    public function test_a_category_of_another_own_group_is_rejected_but_its_copy_in_the_group_is_accepted(): void
    {
        $own = CostCategory::create(['name' => 'Lohnkosten', 'user_group_id' => $this->groupOf($this->user)->id, 'is_relevant_for_billing' => false]);
        $shared = $this->postJson(route('api.groups.store'), ['name' => 'Gemeinsam'])->json('id');
        $this->managementGroup->update(['user_group_id' => $shared]);
        $issuer = InvoiceIssuer::create(['user_group_id' => $shared, 'name' => 'Bank', 'street' => 'S', 'postal_code' => '1', 'city' => 'A']);
        $copy = CostCategory::create(['name' => 'Lohnkosten', 'user_group_id' => $shared, 'is_relevant_for_billing' => false]);

        $this->postJson(route('api.invoices.store'), $this->loan(['invoice_issuer_id' => $issuer->id, 'allocations' => [['cost_category_id' => $own->id]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('allocations.0.cost_category_id');
        $this->postJson(route('api.invoices.store'), $this->loan(['invoice_issuer_id' => $issuer->id, 'allocations' => [['cost_category_id' => $copy->id]]]))
            ->assertCreated();
    }

    // KI-generiert
    public function test_an_issuer_of_another_own_group_is_rejected(): void
    {
        $shared = $this->postJson(route('api.groups.store'), ['name' => 'Gemeinsam'])->json('id');
        $this->managementGroup->update(['user_group_id' => $shared]);

        $this->postJson(route('api.invoices.store'), $this->loan(['allocations' => [['cost_category_id' => $this->interest->id]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('invoice_issuer_id');
        $this->assertSame(0, Invoice::count());
    }

    // KI-generiert
    public function test_a_pinned_interval_holds_every_row(): void
    {
        $pin = $this->managementGroup->billingIntervals()->create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $invoice = $this->createLoan(['billing_interval_id' => $pin->id]);

        $this->assertSame([$pin->id], $invoice->costs()->distinct()->pluck('billing_interval_id')->all());
    }

    // KI-generiert
    public function test_segments_change_amount_and_note_over_time(): void
    {
        $payload = $this->loan(['allocations' => [['cost_category_id' => $this->interest->id]], 'segments' => [
            ['start_date' => '2026-01-01', 'end_date' => '2026-02-28', 'amount' => 100, 'note' => 'Tarif alt'],
            ['start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'amount' => 110, 'note' => 'Tarif neu'],
        ]]);
        unset($payload['amount'], $payload['recurrence_start'], $payload['recurrence_end']);

        $invoice = Invoice::findOrFail($this->postJson(route('api.invoices.store'), $payload)->assertCreated()->json('id'));

        $this->assertSame(100.0, (float) $invoice->amount);
        $this->assertSame(['Tarif alt', 'Tarif alt', 'Tarif neu'], $invoice->costs()->orderBy('period_date')->pluck('note')->all());
    }

    // KI-generiert
    public function test_segments_are_remembered_and_an_extension_replaces_them_from_its_start(): void
    {
        $payload = $this->loan(['allocations' => [['cost_category_id' => $this->interest->id]], 'segments' => [
            ['start_date' => '2026-01-01', 'end_date' => '2026-02-28', 'amount' => 100],
            ['start_date' => '2026-03-01', 'amount' => 110],
        ]]);
        unset($payload['amount'], $payload['recurrence_start'], $payload['recurrence_end']);
        $invoice = Invoice::findOrFail($this->postJson(route('api.invoices.store'), $payload)->assertCreated()->json('id'));

        $this->assertSame([
            ['start_date' => '2026-01-01', 'end_date' => '2026-02-28', 'amount' => 100],
            ['start_date' => '2026-03-01', 'end_date' => null, 'amount' => 110],
        ], $invoice->amount_segments);
        $this->assertSame(110.0, $invoice->expectedAmountOn('2026-05-01'));
        $this->assertSame(100.0, $invoice->expectedAmountOn('2026-02-01'));

        $this->postJson(route('api.invoices.series.store', $invoice), [
            'allocations' => [['cost_category_id' => $this->interest->id]],
            'segments' => [['start_date' => '2026-06-01', 'end_date' => '2026-08-01', 'amount' => 130]],
        ])->assertOk();

        $this->assertSame([
            ['start_date' => '2026-01-01', 'end_date' => '2026-02-28', 'amount' => 100],
            ['start_date' => '2026-03-01', 'end_date' => '2026-05-31', 'amount' => 110],
            ['start_date' => '2026-06-01', 'end_date' => '2026-08-01', 'amount' => 130],
        ], $invoice->fresh()->amount_segments);
        $this->assertSame((float) $invoice->amount, $invoice->fresh()->expectedAmountOn('2025-12-01'));
    }

    // KI-generiert
    public function test_invalid_segments_are_rejected(): void
    {
        $base = $this->loan(['allocations' => [['cost_category_id' => $this->interest->id]]]);
        unset($base['amount'], $base['recurrence_start'], $base['recurrence_end']);
        $gap = [['start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'amount' => 1], ['start_date' => '2026-03-01', 'amount' => 1]];
        $openFirst = [['start_date' => '2026-01-01', 'amount' => 1], ['start_date' => '2026-02-01', 'amount' => 1]];
        $mixed = [['start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'amount' => 1, 'allocations' => [['cost_category_id' => $this->interest->id]]]];

        $this->postJson(route('api.invoices.store'), [...$base, 'segments' => $gap])->assertJsonValidationErrors('segments.1.start_date');
        $this->postJson(route('api.invoices.store'), [...$base, 'segments' => $openFirst])->assertJsonValidationErrors('segments.0.end_date');
        $this->postJson(route('api.invoices.store'), [...$base, 'segments' => $mixed])->assertJsonValidationErrors('segments');
        $this->postJson(route('api.invoices.store'), [...collect($base)->except('allocations')->all(), 'segments' => [$gap[0]]])->assertJsonValidationErrors('segments');
        $this->postJson(route('api.invoices.store'), [...$base, 'amount' => 5, 'segments' => $gap])->assertJsonValidationErrors('amount');
        $this->postJson(route('api.invoices.store'), [...$base, 'periodicity' => 'once', 'segments' => $gap])->assertJsonValidationErrors('periodicity');
    }

    // KI-generiert
    public function test_a_single_segment_ending_before_it_starts_is_rejected(): void
    {
        $payload = $this->loan(['allocations' => [['cost_category_id' => $this->interest->id]], 'segments' => [
            ['start_date' => '2026-03-01', 'end_date' => '2026-01-31', 'amount' => 100],
        ]]);
        unset($payload['amount'], $payload['recurrence_start'], $payload['recurrence_end']);

        $this->postJson(route('api.invoices.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('recurrence_end');
    }

    // KI-generiert
    public function test_a_foreign_issuer_or_costable_is_forbidden(): void
    {
        $stranger = User::factory()->create();

        $this->postJson(route('api.invoices.store'), $this->loan(['invoice_issuer_id' => $this->makeIssuer($stranger)->id]))->assertForbidden();
        $this->postJson(route('api.invoices.store'), $this->loan(['costable' => 'management_group:'.$this->makeManagementGroup($stranger)->id]))->assertForbidden();
    }

    // KI-generiert
    public function test_bookings_upgrade_their_terms_and_a_single_deviation_keeps_the_forecast(): void
    {
        $invoice = $this->createLoan();

        $response = $this->postJson(route('api.invoices.bookings.store', $invoice), ['bookings' => [
            ['booking_date' => '2026-01-30', 'allocations' => [
                ['cost_category_id' => $this->interest->id, 'amount' => 299], ['cost_category_id' => $this->repayment->id, 'amount' => 201],
            ]],
        ]])->assertOk();

        $this->assertCount(2, $response->json('costs'));
        $interest = $invoice->costs()->where('cost_category_id', $this->interest->id)->orderBy('period_date')->get();
        $this->assertSame(['2026-01-30', 299.0], [$interest[1]->booking_date->toDateString(), (float) $interest[1]->amount]);
        $this->assertSame([300.0, 299.0, 300.0, 300.0], $interest->map(fn ($c) => (float) $c->amount)->all());
    }

    // KI-generiert
    public function test_varying_bookings_move_the_last_amount_into_later_open_terms(): void
    {
        $invoice = $this->createLoan();
        $book = fn (string $date, float $interest) => ['booking_date' => $date, 'allocations' => [
            ['cost_category_id' => $this->interest->id, 'amount' => $interest],
            ['cost_category_id' => $this->repayment->id, 'amount' => 500 - $interest],
        ]];

        $this->postJson(route('api.invoices.bookings.store', $invoice), ['bookings' => [$book('2026-01-30', 299), $book('2026-02-27', 298)]])
            ->assertOk();

        $interest = $invoice->costs()->where('cost_category_id', $this->interest->id)->orderBy('period_date')->get();
        $this->assertSame([300.0, 299.0, 298.0, 298.0], $interest->map(fn ($c) => (float) $c->amount)->all());
    }

    // KI-generiert
    public function test_the_forecast_refresh_can_be_switched_off_and_resending_changes_nothing(): void
    {
        $invoice = $this->createLoan(['allocations' => [['cost_category_id' => $this->interest->id]], 'disable_amount_cap' => true]);
        $booking = ['bookings' => [['booking_date' => '2026-02-01', 'amount' => 480]], 'refresh_forecast' => false];

        $this->postJson(route('api.invoices.bookings.store', $invoice), $booking)->assertOk();
        $this->postJson(route('api.invoices.bookings.store', $invoice), $booking)->assertOk();

        $this->assertSame([500.0, 480.0, 500.0, 500.0], $invoice->costs()->orderBy('period_date')->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(1, $invoice->costs()->whereNotNull('booking_date')->count());
    }

    // KI-generiert
    public function test_a_bare_amount_needs_a_single_category_and_bookings_can_be_pinned(): void
    {
        $invoice = $this->createLoan();
        $pin = $this->managementGroup->billingIntervals()->create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

        $this->postJson(route('api.invoices.bookings.store', $invoice), ['bookings' => [['booking_date' => '2026-02-01', 'amount' => 1]]])
            ->assertJsonValidationErrors('bookings.0.allocations');
        $this->postJson(route('api.invoices.bookings.store', $invoice), [
            'billing_interval_id' => $pin->id,
            'bookings' => [['booking_date' => '2026-02-01', 'allocations' => [['cost_category_id' => $this->interest->id, 'amount' => 300]]]],
        ])->assertOk();

        $this->assertSame($pin->id, $invoice->costs()->whereNotNull('booking_date')->sole()->billing_interval_id);
    }

    // KI-generiert
    public function test_bookings_need_edit_rights(): void
    {
        $stranger = User::factory()->create();
        $foreign = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer($stranger)->id, 'amount' => 1, 'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => 'management_group', 'costable_id' => $this->makeManagementGroup($stranger)->id,
        ]);
        $orphan = Invoice::create(['invoice_issuer_id' => $this->makeIssuer($this->user)->id, 'amount' => 1, 'invoice_date' => '2026-01-01', 'periodicity' => 'once']);

        $this->postJson(route('api.invoices.bookings.store', $foreign), ['bookings' => [['booking_date' => '2026-01-01', 'amount' => 1]]])->assertForbidden();
        $this->postJson(route('api.invoices.bookings.store', $orphan), ['bookings' => [['booking_date' => '2026-01-01', 'amount' => 1]]])->assertForbidden();
    }

    // KI-generiert
    public function test_rent_payments_are_booked_by_their_payment_date(): void
    {
        $lease = $this->makeLease($this->user, $this->makeRentalUnit($this->user, true, $this->makeManagementUnit($this->user, $this->managementGroup)));

        $result = $this->postJson(route('api.leases.rent-payments.book', $lease), ['booking_dates' => ['2026-01-29', '2025-01-05']])
            ->assertOk()->json();

        $this->assertSame('2026-02-01', substr($result[0]['rent_payment']['period_date'], 0, 10));
        $this->assertNull($result[1]['rent_payment']);
        $this->assertSame('2026-01-29', RentPayment::whereNotNull('booking_date')->sole()->booking_date->toDateString());
    }

    // KI-generiert
    public function test_coupled_and_foreign_leases_cannot_be_booked(): void
    {
        $unit = $this->makeManagementUnit($this->user, $this->managementGroup);
        $main = $this->makeLease($this->user, $this->makeRentalUnit($this->user, true, $unit));
        $coupled = $this->makeLease($this->user, $this->makeRentalUnit($this->user, false, $unit), ['coupled_to_lease_id' => $main->id]);
        $foreign = $this->makeLease(User::factory()->create());

        $this->postJson(route('api.leases.rent-payments.book', $coupled), ['booking_dates' => ['2026-02-01']])->assertJsonValidationErrors('booking_dates');
        $this->postJson(route('api.leases.rent-payments.book', $foreign), ['booking_dates' => ['2026-02-01']])->assertForbidden();
        $this->postJson(route('api.leases.rent-payments.book', $main), ['booking_dates' => ['1.2.2026']])->assertJsonValidationErrors('booking_dates.0');
    }

    // KI-generiert
    public function test_the_invoice_list_finds_an_invoice_by_issuer_costable_and_date(): void
    {
        $invoice = $this->createLoan();
        $this->createLoan(['invoice_date' => '2026-01-02']);
        $other = $this->makeManagementGroup($this->user);

        $query = ['invoice_issuer_id' => $invoice->invoice_issuer_id, 'invoice_date' => '2026-01-01', 'show_completed' => 1];

        $this->getJson(route('api.invoices.index', [...$query, 'costable' => "management_group:{$this->managementGroup->id}"]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $invoice->id);
        $this->getJson(route('api.invoices.index', [...$query, 'costable' => "management_group:{$other->id}"]))->assertJsonCount(0, 'data');
        $this->getJson(route('api.invoices.index', ['costable' => 'kaputt']))->assertJsonValidationErrors('costable');
    }

    // KI-generiert
    public function test_an_existing_series_can_be_extended_without_duplicating_terms(): void
    {
        $invoice = $this->createLoan(['allocations' => [['cost_category_id' => $this->interest->id]]]);
        $extension = [
            'recurrence_start' => '2026-03-01', 'recurrence_end' => '2026-06-01',
            'allocations' => [['cost_category_id' => $this->interest->id]],
        ];

        $this->postJson(route('api.invoices.series.store', $invoice), $extension)->assertOk()->assertJsonCount(6, 'costs');
        $this->postJson(route('api.invoices.series.store', $invoice), $extension)->assertOk()->assertJsonCount(6, 'costs');

        $this->assertSame(['2026-03-01', '2026-06-01'], [$invoice->fresh()->recurrence_start->toDateString(), $invoice->fresh()->recurrence_end->toDateString()]);
    }

    // KI-generiert
    public function test_extending_accepts_segments_and_rejects_manual_and_foreign_invoices(): void
    {
        $invoice = $this->createLoan(['allocations' => [['cost_category_id' => $this->interest->id]]]);
        $manual = Invoice::create([
            'invoice_issuer_id' => $invoice->invoice_issuer_id, 'amount' => 1, 'invoice_date' => '2026-01-01', 'periodicity' => 'manual',
            'costable_type' => 'management_group', 'costable_id' => $this->managementGroup->id,
        ]);
        $stranger = User::factory()->create();
        $foreign = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer($stranger)->id, 'amount' => 1, 'invoice_date' => '2026-01-01', 'periodicity' => 'monthly',
            'costable_type' => 'management_group', 'costable_id' => $this->makeManagementGroup($stranger)->id,
        ]);
        $segments = ['allocations' => [['cost_category_id' => $this->interest->id]], 'segments' => [
            ['start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'amount' => 550, 'note' => 'teurer'],
        ]];

        $this->postJson(route('api.invoices.series.store', $invoice), $segments)->assertOk();
        $this->postJson(route('api.invoices.series.store', $manual), $segments)->assertUnprocessable();
        $this->postJson(route('api.invoices.series.store', $foreign), $segments)->assertForbidden();
        $this->assertSame('teurer', $invoice->costs()->whereDate('period_date', '2026-05-01')->sole()->note);
    }

    // KI-generiert
    public function test_claims_and_transfers_can_be_read_back_for_a_repeated_import(): void
    {
        $lease = $this->makeLease($this->user, $this->makeRentalUnit($this->user, true, $this->makeManagementUnit($this->user, $this->managementGroup)));
        $lease->settlementClaims()->create(['date' => '2026-03-01', 'amount' => 42, 'billing_interval_id' => $this->managementGroup->billingIntervals()->first()->id]);
        $from = $this->managementGroup->accounts()->create(['name' => 'Giro']);
        $to = $this->managementGroup->accounts()->create(['name' => 'Rücklage']);
        $from->transfersOut()->create(['to_account_id' => $to->id, 'amount' => 10, 'transfer_date' => '2026-02-01']);

        $this->getJson(route('api.leases.show', $lease))->assertJsonPath('settlement_claims.0.amount', 42);
        $this->getJson(route('api.management-groups.show', $this->managementGroup))
            ->assertJsonPath('accounts.0.transfers_out.0.to_account_id', $to->id);
    }

    // KI-generiert
    public function test_invoices_of_a_lease_are_listed_too(): void
    {
        $lease = $this->makeLease($this->user, $this->makeRentalUnit($this->user, true, $this->makeManagementUnit($this->user, $this->managementGroup)));
        $invoice = $this->createLoan(['costable' => "lease:{$lease->id}"]);

        $this->getJson(route('api.invoices.index', ['show_completed' => 1]))->assertJsonFragment(['id' => $invoice->id]);
    }
}
