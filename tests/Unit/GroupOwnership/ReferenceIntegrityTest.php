<?php

// KI-generiert

namespace Tests\Unit\GroupOwnership;

use App\Exceptions\GroupMismatchException;
use App\Models\BillingTemplate;
use App\Models\User;
use App\Services\GroupOwnership\ReferenceIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

class ReferenceIntegrityTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    public function test_missing_references_are_not_checked(): void
    {
        $managementGroup = $this->makeManagementGroup(User::factory()->create());

        ReferenceIntegrity::assertIntervalBelongsTo(null, $managementGroup);
        ReferenceIntegrity::assertIntervalBelongsTo(1, null);
        ReferenceIntegrity::assertAccountBelongsTo(null, $managementGroup);
        ReferenceIntegrity::assertAccountBelongsTo(1, null);
        ReferenceIntegrity::assertIssuerAccountBelongsTo(null, 1);
        ReferenceIntegrity::assertIssuerAccountBelongsTo(1, null);
        ReferenceIntegrity::assertTemplateFits(null, $managementGroup->user_group_id);

        $this->addToAssertionCount(1);
    }

    public function test_own_intervals_pass_and_foreign_ones_name_the_field(): void
    {
        $own = $this->makeManagementGroup(User::factory()->create());
        $foreign = $this->makeManagementGroup(User::factory()->create());

        ReferenceIntegrity::assertIntervalBelongsTo($own->billingIntervals()->value('id'), $own);

        try {
            ReferenceIntegrity::assertIntervalBelongsTo($foreign->billingIntervals()->value('id'), $own, 'pin');
            $this->fail('Ein fremdes Abrechnungsintervall hätte abgelehnt werden müssen.');
        } catch (GroupMismatchException $e) {
            $this->assertSame('pin', $e->field);
        }
    }

    public function test_a_template_fits_its_own_group_and_a_builtin_one_fits_everywhere(): void
    {
        $groupId = $this->groupOf(User::factory()->create())->id;
        $own = BillingTemplate::create(['user_group_id' => $groupId, 'name' => 'Eigen', 'content' => 'x']);
        $builtin = BillingTemplate::create(['user_group_id' => null, 'name' => 'Eingebaut', 'content' => 'x']);

        $this->assertTrue(ReferenceIntegrity::templateFits($own, $groupId));
        $this->assertFalse(ReferenceIntegrity::templateFits($own, $groupId + 1));
        $this->assertTrue(ReferenceIntegrity::templateFits($builtin, $groupId + 1));

        $this->expectException(GroupMismatchException::class);
        ReferenceIntegrity::assertTemplateFits($own->id, $groupId + 1);
    }
}
