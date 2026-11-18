<?php

namespace Tests\Feature\Api;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceSeries\InvoiceSeriesService;
use App\Services\RentPaymentGenerator;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class OpenBookingControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private const TODAY = '2026-04-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CostCategorySeeder::class);
        Carbon::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeOpenBookings(User $user): void
    {
        $lease = $this->makeLease($user);
        app(RentPaymentGenerator::class)->generate($lease->leaseIntervals->first());

        $managementUnit = $lease->rentalUnit->managementUnit;
        $issuerId = $this->makeIssuer($user)->id;
        foreach (['2026-02-01', '2026-06-01'] as $invoiceDate) {
            $invoice = Invoice::create([
                'invoice_issuer_id' => $issuerId, 'invoice_date' => $invoiceDate, 'amount' => 100, 'periodicity' => 'once',
            ]);
            app(InvoiceSeriesService::class)->write($invoice, $managementUnit, CostCategory::first());
        }
    }

    public function test_open_costs_and_rent_payments_up_to_today_are_listed(): void
    {
        $user = $this->apiUser();
        $this->makeOpenBookings($user);

        $response = $this->getJson(route('api.open-bookings.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'costs')
            ->assertJsonPath('costs.0.invoice.invoice_issuer.name', 'Stadtwerke')
            ->assertJsonCount(4, 'rent_payments');
    }

    public function test_booked_and_foreign_bookings_are_not_listed(): void
    {
        $this->makeOpenBookings(User::factory()->create());
        $user = $this->apiUser();
        $this->makeOpenBookings($user);
        Cost::query()->update(['booking_date' => self::TODAY]);

        $response = $this->getJson(route('api.open-bookings.index'));

        $response->assertOk()->assertJsonCount(0, 'costs')->assertJsonCount(4, 'rent_payments');
    }
}
