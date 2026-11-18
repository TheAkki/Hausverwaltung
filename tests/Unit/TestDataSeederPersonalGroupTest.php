<?php

// KI-generiert

namespace Tests\Unit;

use App\Models\Group;
use App\Models\Landlord;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\TestDataSeeder;

/**
 * Die persönliche Gruppe ("Gruppe von <Name>") entsteht automatisch beim Anlegen des
 * Benutzers (UserObserver). Der Seeder legt sie nie selbst an, sondern nutzt sie –
 * auch dann, wenn der Benutzer außerdem Mitglied gemeinsamer Gruppen ist.
 */
class TestDataSeederPersonalGroupTest extends TestDataSeederTestCase
{
    private const ACCOUNT = <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Vermieterin"
            Passwort: "geheim123"

            Vermieter:
                Name: "Erika Muster"

            Verwaltungsgruppe-1:
                Beschreibung: "Eigene VG"
        YAML;

    // KI-generiert
    public function test_the_account_uses_the_automatically_created_personal_group_exactly_once(): void
    {
        file_put_contents($this->path, self::ACCOUNT);

        $this->seed(TestDataSeeder::class);
        $this->seed(TestDataSeeder::class);

        $user = User::where('name', 'Vermieterin')->firstOrFail();
        $this->assertSame(1, Group::where('name', 'Gruppe von Vermieterin')->count());
        $personal = $user->personalGroup();
        $this->assertSame('Gruppe von Vermieterin', $personal->name);
        $this->assertSame($personal->id, ManagementGroup::where('description', 'Eigene VG')->value('user_group_id'));
        $this->assertSame($personal->id, Landlord::whereNull('management_group_id')->value('user_group_id'));
    }

    // KI-generiert
    public function test_an_earlier_shared_membership_does_not_replace_the_personal_group(): void
    {
        $user = User::factory()->create(['name' => 'Vermieterin']);
        $other = User::factory()->create();
        $shared = Group::create(['name' => 'Fremde GbR', 'owner_id' => $other->id]);
        $shared->users()->attach($user->id, ['role' => 'user']);
        // Gemeinsame Gruppe zuerst in der Mitgliederliste – früher nahm der Seeder
        // einfach die erste Gruppe des Benutzers.
        $user->groups()->detach($user->personalGroup()->id);
        $user->groups()->attach($user->personalGroup()->id, ['role' => 'admin']);
        file_put_contents($this->path, self::ACCOUNT);

        $this->seed(TestDataSeeder::class);

        $this->assertSame($user->personalGroup()->id, ManagementGroup::where('description', 'Eigene VG')->value('user_group_id'));
        $this->assertSame(0, ManagementGroup::where('user_group_id', $shared->id)->count());
    }

    // KI-generiert
    public function test_a_missing_personal_group_is_an_error_instead_of_being_created(): void
    {
        $user = User::factory()->create(['name' => 'Vermieterin']);
        $user->personalGroup()->delete();
        file_put_contents($this->path, self::ACCOUNT);

        try {
            $this->seed(TestDataSeeder::class);
            $this->fail('Der Seeder hätte abbrechen müssen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('persönliche Gruppe fehlt', $e->getMessage());
        }

        $this->assertSame(0, Group::where('name', 'Gruppe von Vermieterin')->count());
    }

    // KI-generiert
    public function test_the_personal_group_is_the_first_owned_group_not_a_shared_one(): void
    {
        $user = User::factory()->create(['name' => 'Anna']);
        $ownShared = Group::create(['name' => 'Eigene GbR', 'owner_id' => $user->id]);
        $foreign = Group::create(['name' => 'Fremde GbR', 'owner_id' => User::factory()->create()->id]);
        $foreign->users()->attach($user->id, ['role' => 'user']);

        $this->assertSame('Gruppe von Anna', $user->personalGroup()->name);
        $this->assertNotSame($ownShared->id, $user->personalGroup()->id);

        $user->personalGroup()->delete();
        $this->assertSame($ownShared->id, $user->personalGroup()->id);

        $ownShared->delete();
        $this->assertNull($user->personalGroup());
    }
}
