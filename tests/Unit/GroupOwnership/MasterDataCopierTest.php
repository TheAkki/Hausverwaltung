<?php

// KI-generiert

namespace Tests\Unit\GroupOwnership;

use App\Models\CostCategory;
use App\Models\Group;
use App\Models\InvoiceIssuer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GroupOwnership\MasterDataCopier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataCopierTest extends TestCase
{
    use RefreshDatabase;

    private MasterDataCopier $copier;

    private int $own;

    private int $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->copier = new MasterDataCopier;
        $user = User::factory()->create();
        $this->own = $user->groups()->first()->id;
        $this->shared = Group::create(['name' => 'Gemeinsam', 'owner_id' => $user->id])->id;
    }

    private function issuer(int $groupId, string $name = 'Stadtwerke'): InvoiceIssuer
    {
        return InvoiceIssuer::create(['user_group_id' => $groupId, 'name' => $name, 'street' => 'A', 'postal_code' => '1', 'city' => 'B', 'iban' => 'DE02120300000000202051']);
    }

    public function test_an_issuer_is_copied_with_all_its_customer_accounts(): void
    {
        $issuer = $this->issuer($this->own);
        $water = $issuer->accounts()->create(['customer_number' => 'W-1', 'description' => 'Wasser', 'direct_debit' => true]);
        $power = $issuer->accounts()->create(['customer_number' => 'S-1']);

        [$copy, $created] = $this->copier->copyTo($issuer, $this->shared);

        $this->assertTrue($created);
        $this->assertNotSame($issuer->id, $copy->id);
        $this->assertSame([$this->shared, 'Stadtwerke', 'DE02120300000000202051'], [$copy->user_group_id, $copy->name, $copy->iban]);
        $this->assertSame(['S-1', 'W-1'], $copy->accounts()->orderBy('customer_number')->pluck('customer_number')->all());
        $this->assertTrue($copy->accounts()->where('customer_number', 'W-1')->sole()->direct_debit);
        $this->assertSame($this->own, $issuer->fresh()->user_group_id);
        $this->assertSame([$water->id, $power->id], $issuer->accounts()->orderBy('id')->pluck('id')->all());
    }

    public function test_an_equal_issuer_of_the_group_is_reused_and_completed(): void
    {
        $issuer = $this->issuer($this->own);
        $issuer->accounts()->create(['customer_number' => 'W-1']);
        $missing = $issuer->accounts()->create(['customer_number' => 'S-1']);
        $existing = $this->issuer($this->shared);
        $existingAccount = $existing->accounts()->create(['customer_number' => 'W-1']);

        [$target, $created] = $this->copier->copyTo($issuer, $this->shared);
        $map = $this->copier->accountMap($issuer, $target);

        $this->assertFalse($created);
        $this->assertTrue($target->is($existing));
        $this->assertSame(2, $existing->accounts()->count());
        $this->assertSame($existingAccount->id, $map[$issuer->accounts()->where('customer_number', 'W-1')->sole()->id]);
        $this->assertSame($existing->accounts()->where('customer_number', 'S-1')->sole()->id, $map[$missing->id]);
    }

    public function test_an_entry_already_in_the_group_is_returned_unchanged(): void
    {
        $issuer = $this->issuer($this->shared);
        $account = $issuer->accounts()->create(['customer_number' => 'W-1']);

        [$target, $created] = $this->copier->copyTo($issuer, $this->shared);

        $this->assertFalse($created);
        $this->assertTrue($target->is($issuer));
        $this->assertSame([$account->id => $account->id], $this->copier->accountMap($issuer, $issuer));
    }

    public function test_a_different_issuer_of_the_same_name_elsewhere_is_not_mistaken_for_an_equal_one(): void
    {
        $issuer = $this->issuer($this->own);
        InvoiceIssuer::create(['user_group_id' => $this->shared, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '9', 'city' => 'B']);

        $this->assertNull($this->copier->findEqual($issuer, $this->shared));
        $this->assertTrue($this->copier->copyTo($issuer, $this->shared)[1]);
    }

    public function test_tenants_and_categories_are_found_or_copied(): void
    {
        $tenant = Tenant::create(['user_group_id' => $this->own, 'first_name' => 'Anna', 'last_name' => 'Muster', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::create(['user_group_id' => $this->own, 'name' => 'Lohn', 'is_relevant_for_billing' => false]);
        $existing = CostCategory::create(['user_group_id' => $this->shared, 'name' => 'Lohn', 'is_relevant_for_billing' => false]);

        [$tenantCopy, $tenantCreated] = $this->copier->copyTo($tenant, $this->shared);
        [$categoryTarget, $categoryCreated] = $this->copier->copyTo($category, $this->shared);

        $this->assertTrue($tenantCreated);
        $this->assertSame([$this->shared, 'Muster'], [$tenantCopy->user_group_id, $tenantCopy->last_name]);
        $this->assertFalse($categoryCreated);
        $this->assertTrue($categoryTarget->is($existing));
        $this->assertTrue($this->copier->findEqual($tenant, $this->shared)->is($tenantCopy));
    }
}
