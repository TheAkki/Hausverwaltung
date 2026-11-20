<?php

namespace Tests\Unit;

use App\Models\BillingTemplate;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use App\Models\InvoiceOccurrence;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\SettlementClaim;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers a handful of inverse/convenience Eloquent relations that aren't exercised
 * by any controller or view, but are part of each model's public API.
 */
class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_belongs_to_its_user_group(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $tenant = Tenant::create([
            'user_group_id' => $group->id, 'first_name' => 'Max', 'last_name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        $this->assertInstanceOf(Group::class, $tenant->userGroup);
        $this->assertSame($group->id, $tenant->userGroup->id);
    }

    public function test_invoice_issuer_belongs_to_its_user_group(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $issuer = InvoiceIssuer::create([
            'user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        $this->assertInstanceOf(Group::class, $issuer->userGroup);
        $this->assertSame($group->id, $issuer->userGroup->id);
    }

    public function test_invoice_issuer_account_has_many_invoices(): void
    {
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $account = InvoiceIssuerAccount::create(['invoice_issuer_id' => $issuer->id, 'customer_number' => '123']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'invoice_issuer_account_id' => $account->id,
            'amount' => 100, 'invoice_date' => '2026-01-01', 'periodicity' => 'once',
        ]);

        $this->assertTrue($account->invoices->contains($invoice->id));
    }

    public function test_invoice_occurrence_belongs_to_its_invoice(): void
    {
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 100, 'invoice_date' => '2026-01-01', 'periodicity' => 'manual',
        ]);
        $occurrence = InvoiceOccurrence::create([
            'invoice_id' => $invoice->id, 'occurrence_date' => '2026-02-01', 'amount' => 50,
        ]);

        $this->assertSame($invoice->id, $occurrence->invoice->id);
    }

    public function test_billing_interval_belongs_to_its_management_group(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $billingInterval = $managementGroup->billingIntervals()->first();

        $this->assertSame($managementGroup->id, $billingInterval->managementGroup->id);
    }

    public function test_billing_template_has_many_management_groups_using_it_as_default(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['name' => 'Standard', 'content' => '<p>Test</p>']);
        $managementGroup = ManagementGroup::create([
            'description' => 'MG', 'user_group_id' => $group->id, 'default_billing_template_id' => $template->id,
        ]);

        $this->assertTrue($template->managementGroups->contains($managementGroup->id));
    }

    public function test_a_settlement_claim_belongs_to_its_lease_and_billing_interval_and_reports_whether_it_is_open(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60]);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $billingInterval = $managementGroup->billingIntervals()->first();

        $claim = SettlementClaim::create([
            'lease_id' => $lease->id, 'billing_interval_id' => $billingInterval->id,
            'amount' => 150, 'date' => '2026-06-01',
        ]);

        $this->assertTrue($lease->settlementClaims->contains($claim->id));
        $this->assertSame($lease->id, $claim->lease->id);
        $this->assertSame($billingInterval->id, $claim->billingInterval->id);
        $this->assertTrue($claim->isOpen());

        $claim->update(['booking_date' => '2026-06-15']);
        $this->assertFalse($claim->fresh()->isOpen());
    }
}
