<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\User;
use App\Services\BackupService;
use App\Services\GroupOwnership\GroupOwnership;
use App\Support\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * Ein Backup stellt den gesicherten Zustand wieder her, wie er war – auch Altdaten,
 * die der Gruppenregel (GroupOwnership) noch nicht entsprechen.
 */
class GroupOwnershipBackupTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    public function test_a_backup_with_a_tenant_of_another_own_group_is_restored(): void
    {
        Storage::fake(FileStorage::diskName());
        $user = User::factory()->create();
        $shared = Group::create(['name' => 'Gemeinsam', 'owner_id' => $user->id]);
        $shared->users()->attach($user->id, ['role' => 'admin']);
        $managementGroup = ManagementGroup::create(['description' => 'Alt', 'user_group_id' => $shared->id]);
        $rentalUnit = $this->makeRentalUnit($user, managementUnit: $this->makeManagementUnit($user, $managementGroup));
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $tenant = $this->makeTenant($user, 'Privat');
        GroupOwnership::suspended(fn () => $lease->tenants()->attach($tenant->id));

        $zipPath = app(BackupService::class)->export($user->fresh());
        app(BackupService::class)->import($user->fresh(), $zipPath);
        unlink($zipPath);

        $restored = ManagementGroup::where('description', 'Alt')->whereKeyNot($managementGroup->id)->sole();
        $restoredLease = Lease::whereHas('rentalUnit.managementUnit', fn ($q) => $q->where('management_group_id', $restored->id))->sole();
        $this->assertSame('Privat', $restoredLease->tenants()->sole()->last_name);
        $this->assertFalse(GroupOwnership::isSuspended());
    }
}
