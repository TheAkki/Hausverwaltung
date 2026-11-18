<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RentPaymentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RentPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_newly_generated_rent_payment_is_assigned_to_the_default_konto(): void
    {
        Carbon::setTestNow('2026-02-15');

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id, 'kontosystem_enabled' => true]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $managementGroup->accounts()->create(['name' => 'Nebenkonto']);
        $defaultAccount = $managementGroup->accounts()->create(['name' => 'Girokonto', 'is_default' => true]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        app(RentPaymentGenerator::class)->generate($lease->leaseIntervals->first());

        $payments = RentPayment::all();
        $this->assertCount(2, $payments);
        foreach ($payments as $payment) {
            $this->assertTrue($payment->accounts->contains($defaultAccount->id));
            $this->assertSame(500.0, (float) $payment->accounts->first()->pivot->amount);
        }

        // Clearing the Konto on one payment must not be reattached on the next generation.
        $payments->first()->accounts()->detach();
        app(RentPaymentGenerator::class)->generate($lease->leaseIntervals->first());
        $this->assertFalse($payments->first()->fresh()->accounts->contains($defaultAccount->id));

        Carbon::setTestNow();
    }

    public function test_viewing_a_lease_generates_rent_payments_for_every_elapsed_month(): void
    {
        Carbon::setTestNow('2026-04-15');

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->get(route('leases.show', $lease));
        $response->assertOk();

        $interval = $lease->fresh()->leaseIntervals->first();
        $payments = $interval->rentPayments()->orderBy('period_date')->get();

        // Jan, Feb, Mar, Apr = 4 elapsed months as of 2026-04-15.
        $this->assertCount(4, $payments);
        $this->assertSame(
            ['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01'],
            $payments->map(fn ($p) => $p->period_date->toDateString())->all()
        );
        $this->assertTrue($payments->every(fn ($p) => $p->isOpen()));

        Carbon::setTestNow();
    }

    public function test_setting_a_booking_date_marks_the_payment_as_booked(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        app(RentPaymentGenerator::class)->generate($lease->leaseIntervals->first());
        $payment = RentPayment::first();
        $this->assertTrue($payment->isOpen());

        $response = $this->actingAs($user)->put(route('rent-payments.update', $payment), [
            'booking_date' => '2026-01-31',
        ]);

        $response->assertRedirect(route('leases.show', $lease).'#mietzahlungen');
        $this->assertFalse($payment->fresh()->isOpen());
        $this->assertSame('2026-01-31', $payment->fresh()->booking_date->toDateString());
    }

    /**
     * Konzept.md "### Mietvertrag": "…dann wird der gekoppelte Mietvertrag in den
     * Referenzierten Mietvertrag mit eingerechnet." Since a gekoppelter Mietvertrag is
     * never booked on its own (see below), its rent must instead be folded directly
     * into the referenced Mietvertrag's own generated Mietzahlung, so the one real bank
     * transfer the tenant makes matches what actually gets booked there.
     */
    public function test_generating_a_rent_payment_includes_a_coupled_leases_rent(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $wohnung = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $garage = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $wohnungLease = Lease::create([
            'rental_unit_id' => $wohnung->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 622.08,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);
        $wohnungLease->currentLeaseInterval()->update(['utility_advance_payment' => 259.20]);

        $garageLease = Lease::create([
            'rental_unit_id' => $garage->id, 'coupled_to_lease_id' => $wohnungLease->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $garageLease->tenants()->attach($tenant->id);

        $payments = app(RentPaymentGenerator::class)->generate($wohnungLease->currentLeaseInterval()->fresh());

        $this->assertEqualsWithDelta(652.08, (float) $payments->first()->rent_amount, 0.01);
        $this->assertEqualsWithDelta(259.20, (float) $payments->first()->utility_amount, 0.01);
        $this->assertEqualsWithDelta(911.28, (float) $payments->first()->amount, 0.01);

        // The Aufschlüsselung (contributingLeases) makes the merged amount traceable
        // back to what each Mietvertrag actually contributed.
        $contributions = $payments->first()->contributingLeases()->get()->keyBy('id');
        $this->assertCount(2, $contributions);
        $this->assertEqualsWithDelta(881.28, (float) $contributions[$wohnungLease->id]->pivot->amount, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $contributions[$garageLease->id]->pivot->amount, 0.01);

        // The coupled Mietvertrag's own generated Mietzahlung keeps its own nominal
        // rent (for display – it is never bookable, see below) instead of also getting
        // the combined amount, which would double it.
        $garagePayment = app(RentPaymentGenerator::class)->generate($garageLease->currentLeaseInterval()->fresh())->first();
        $this->assertEqualsWithDelta(30.0, (float) $garagePayment->rent_amount, 0.01);
    }

    /**
     * Once a Mietzahlung is gebucht, its Aufschlüsselung is a confirmed historical
     * record just like rent_amount/utility_amount itself – a later change to the
     * gekoppelten Mietvertrags Miete must not silently rewrite it.
     */
    public function test_a_booked_rent_payments_contribution_breakdown_is_frozen(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $wohnung = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $garage = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $wohnungLease = Lease::create([
            'rental_unit_id' => $wohnung->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 622.08,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);

        $garageLease = Lease::create([
            'rental_unit_id' => $garage->id, 'coupled_to_lease_id' => $wohnungLease->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $garageLease->tenants()->attach($tenant->id);

        $payment = app(RentPaymentGenerator::class)->generate($wohnungLease->currentLeaseInterval()->fresh())->first();
        $payment->update(['booking_date' => '2026-01-31']);

        $garageLease->currentLeaseInterval()->update(['rent_amount' => 999]);
        app(RentPaymentGenerator::class)->generate($wohnungLease->currentLeaseInterval()->fresh());

        $contributions = $payment->fresh()->contributingLeases()->get()->keyBy('id');
        $this->assertEqualsWithDelta(30.0, (float) $contributions[$garageLease->id]->pivot->amount, 0.01);
    }

    /**
     * The Aufschlüsselung must actually be visible: the Wohnung's own Mietzahlungen-
     * Tabelle shows the per-Mietvertrag split, and once the payment carrying the
     * Stellplatz's Beitrag is gebucht, the Stellplatz's own page links precisely to it
     * instead of just the generic "wird dort gebucht" hint.
     */
    public function test_the_contribution_breakdown_is_shown_on_both_leases_show_pages(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $wohnung = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $garage = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $wohnungLease = Lease::create([
            'rental_unit_id' => $wohnung->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 622.08,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);

        $garageLease = Lease::create([
            'rental_unit_id' => $garage->id, 'coupled_to_lease_id' => $wohnungLease->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $garageLease->tenants()->attach($tenant->id);

        $payment = app(RentPaymentGenerator::class)->generate($wohnungLease->currentLeaseInterval()->fresh())->first();

        $wohnungResponse = $this->actingAs($user)->get(route('leases.show', $wohnungLease));
        $wohnungResponse->assertOk();
        $wohnungResponse->assertSee('30,00 €', false);

        $garageResponseBeforeBooking = $this->actingAs($user)->get(route('leases.show', $garageLease));
        $garageResponseBeforeBooking->assertSee('Wird beim referenzierten Mietvertrag gebucht');

        $payment->update(['booking_date' => '2026-01-31']);

        $garageResponseAfterBooking = $this->actingAs($user)->get(route('leases.show', $garageLease));
        $garageResponseAfterBooking->assertSee('Gebucht beim referenzierten Mietvertrag');
        $garageResponseAfterBooking->assertSee(route('leases.show', $wohnungLease).'#mietzahlungen', false);
    }

    /**
     * "Es kann ja auch innerhalb eines Intervalls der Stellplatz gekündigt werden. Da
     * muss das berücksichtigt werden." – once the coupled Mietvertrag ends, its rent
     * must stop being folded in from that month onward, even though the referencing
     * Wohnung's own Mietintervall continues unchanged.
     */
    public function test_generating_a_rent_payment_stops_including_a_coupled_leases_rent_after_it_ends(): void
    {
        Carbon::setTestNow('2026-03-15');

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $wohnung = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $garage = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $wohnungLease = Lease::create([
            'rental_unit_id' => $wohnung->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 622.08,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);

        // The Stellplatz-Mietvertrag ends after January (Kündigung) – the Wohnung's own
        // Mietintervall is unaffected and keeps running.
        $garageLease = Lease::create([
            'rental_unit_id' => $garage->id, 'coupled_to_lease_id' => $wohnungLease->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $garageLease->tenants()->attach($tenant->id);

        $payments = app(RentPaymentGenerator::class)->generate($wohnungLease->currentLeaseInterval()->fresh())
            ->keyBy(fn ($p) => $p->period_date->toDateString());

        $this->assertEqualsWithDelta(652.08, (float) $payments['2026-01-01']->rent_amount, 0.01);
        $this->assertEqualsWithDelta(622.08, (float) $payments['2026-02-01']->rent_amount, 0.01);
        $this->assertEqualsWithDelta(622.08, (float) $payments['2026-03-01']->rent_amount, 0.01);

        // The Aufschlüsselung follows the same Kündigung-aware logic: the Stellplatz
        // only shows up as a contributor for the month it was actually still coupled.
        $this->assertCount(2, $payments['2026-01-01']->contributingLeases);
        $this->assertCount(1, $payments['2026-02-01']->contributingLeases);
        $this->assertSame($wohnungLease->id, $payments['2026-02-01']->contributingLeases->first()->id);

        Carbon::setTestNow();
    }

    /**
     * Konzept.md "### Mietvertrag": "Genierie bitte Fehlermeldungen, wenn Buchungen auf
     * ein referenzierenden Mietvertrag gebucht werden." – a Mietzahlung of a Mietvertrag
     * coupled to another one (e.g. ein Stellplatz gekoppelt an die Wohnung desselben
     * Mieters) must not be bookable directly; the payment is booked against the
     * referenced Mietvertrag instead.
     */
    public function test_booking_a_rent_payment_of_a_coupled_lease_is_rejected(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $wohnung = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $garage = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $wohnungLease = Lease::create([
            'rental_unit_id' => $wohnung->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);

        $garageLease = Lease::create([
            'rental_unit_id' => $garage->id, 'coupled_to_lease_id' => $wohnungLease->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $garageLease->tenants()->attach($tenant->id);

        app(RentPaymentGenerator::class)->generate($garageLease->leaseIntervals->first());
        $payment = RentPayment::first();

        $response = $this->actingAs($user)->put(route('rent-payments.update', $payment), [
            'booking_date' => '2026-01-31',
        ]);

        $response->assertSessionHasErrors('booking_date');
        $this->assertTrue($payment->fresh()->isOpen());
    }

    /**
     * Clearing a Buchungsdatum back to open (null) must remain possible even on a
     * coupled lease's Mietzahlung – only setting one is rejected.
     */
    public function test_clearing_a_rent_payments_booking_date_is_allowed_on_a_coupled_lease(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $wohnung = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $garage = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $wohnungLease = Lease::create([
            'rental_unit_id' => $wohnung->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);

        $garageLease = Lease::create([
            'rental_unit_id' => $garage->id, 'coupled_to_lease_id' => $wohnungLease->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $garageLease->tenants()->attach($tenant->id);

        app(RentPaymentGenerator::class)->generate($garageLease->leaseIntervals->first());
        $payment = RentPayment::first();
        $payment->update(['booking_date' => '2026-01-31']);

        $response = $this->actingAs($user)->put(route('rent-payments.update', $payment), [
            'booking_date' => null,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertTrue($payment->fresh()->isOpen());
    }

    public function test_a_rent_payment_can_be_assigned_to_a_konto(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id, 'kontosystem_enabled' => true]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        app(RentPaymentGenerator::class)->generate($lease->leaseIntervals->first());
        $payment = RentPayment::first();

        $response = $this->actingAs($user)->put(route('rent-payments.update', $payment), [
            'booking_date' => '2026-01-31',
            'account_id' => $account->id,
        ]);

        $response->assertRedirect(route('leases.show', $lease).'#mietzahlungen');
        $this->assertTrue($payment->fresh()->accounts->contains($account->id));
        $this->assertSame(500.0, (float) $payment->fresh()->accounts->first()->pivot->amount);

        $showResponse = $this->actingAs($user)->get(route('leases.show', $lease));
        $showResponse->assertOk();
        $showResponse->assertSee('Girokonto');
    }

    public function test_booking_a_rent_payment_without_a_konto_is_rejected_when_the_kontosystem_is_enabled(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id, 'kontosystem_enabled' => true]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        app(RentPaymentGenerator::class)->generate($lease->leaseIntervals->first());
        $payment = RentPayment::first();

        $response = $this->actingAs($user)->put(route('rent-payments.update', $payment), [
            'booking_date' => '2026-01-31',
            'confirm_booking' => '1',
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertTrue($payment->fresh()->isOpen());
    }

    public function test_clicking_buchen_with_the_suggested_placeholder_date_books_it_correctly(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id, 'kontosystem_enabled' => true]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        app(RentPaymentGenerator::class)->generate($lease->leaseIntervals->first());
        $payment = RentPayment::first();
        $suggestedDate = $payment->period_date->toDateString();

        // Simulates clicking "Buchen" without having touched the date field at all — the
        // browser still submits the placeholder value shown in the input, which must be
        // accepted, not require picking a different date first.
        $response = $this->actingAs($user)->put(route('rent-payments.update', $payment), [
            'booking_date' => $suggestedDate,
            'account_id' => $account->id,
            'confirm_booking' => '1',
        ]);

        $response->assertRedirect(route('leases.show', $lease).'#mietzahlungen');
        $this->assertFalse($payment->fresh()->isOpen());
        $this->assertSame($suggestedDate, $payment->fresh()->booking_date->toDateString());
        $this->assertTrue($payment->fresh()->accounts->contains($account->id));
    }

    public function test_editing_the_utility_advance_payment_updates_still_open_rent_payments(): void
    {
        Carbon::setTestNow('2026-03-15');

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-10',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $interval = $lease->leaseIntervals->first();
        $interval->update(['utility_advance_payment' => 100]);
        app(RentPaymentGenerator::class)->generate($interval);

        $january = $interval->rentPayments()->whereDate('period_date', '2026-01-01')->first();
        $february = $interval->rentPayments()->whereDate('period_date', '2026-02-01')->first();
        $january->update(['booking_date' => '2026-01-05']);

        $this->assertSame(600.0, (float) $january->fresh()->amount);
        $this->assertSame(600.0, (float) $february->fresh()->amount);

        // Raising the Betriebskostenvorauszahlung must retroactively update the still-open
        // Buchungen (Februar/März), but not the already booked January payment.
        $interval->update(['utility_advance_payment' => 150]);
        app(RentPaymentGenerator::class)->generate($interval->fresh());

        $this->assertSame(600.0, (float) $january->fresh()->amount, 'Booked payments must stay fixed.');
        $this->assertSame(650.0, (float) $february->fresh()->amount, 'Open payments must track the new amount.');
        $this->assertSame(150.0, (float) $february->fresh()->utility_amount);

        Carbon::setTestNow();
    }
}
