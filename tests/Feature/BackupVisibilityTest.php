<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\Group;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BackupService;
use App\Support\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Benutzer-Backup (Konzept.md "## API"): Export aller sichtbaren Daten (jede Gruppe,
 * in der der Benutzer Mitglied ist), Import aller schreibbaren Daten. Mitgliedschaften
 * stellt nur ein Gruppen-Admin wieder her – und ein Benutzer-Import legt nie Konten an.
 */
class BackupVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'chefin@hausverwaltung.test';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FileStorage::diskName());
        config(['app.admin_email' => self::ADMIN_EMAIL]);
    }

    private function sharedGroup(User $owner, string $name = 'Erbengemeinschaft'): Group
    {
        $group = Group::create(['name' => $name, 'owner_id' => $owner->id]);
        $group->users()->attach($owner->id, ['role' => 'admin']);

        ManagementGroup::create(['description' => "VG {$name}", 'user_group_id' => $group->id]);
        Tenant::create([
            'user_group_id' => $group->id, 'first_name' => 'Erika', 'last_name' => 'Muster',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        return $group;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportedData(User $user): array
    {
        $path = app(BackupService::class)->export($user);
        $zip = new ZipArchive;
        $zip->open($path);
        $data = json_decode((string) $zip->getFromName('backup.json'), true);
        $zip->close();
        unlink($path);

        return $data;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function backupFile(array $groups, string $type = 'user'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hv-backup-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('backup.json', json_encode([
            'format' => BackupService::FORMAT, 'version' => BackupService::VERSION, 'type' => $type,
            'profile' => [], 'groups' => $groups,
        ]));
        $zip->close();

        return $path;
    }

    private function importAs(User $user, string $path): string
    {
        try {
            return app(BackupService::class)->import($user, $path);
        } finally {
            unlink($path);
        }
    }

    // KI-generiert
    public function test_the_export_contains_every_group_the_user_is_a_member_of_whatever_the_role(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $shared = $this->sharedGroup($owner);
        $shared->users()->attach($member->id, ['role' => 'user']);
        $this->sharedGroup($owner, 'Fremde Gruppe');

        $names = collect($this->exportedData($member)['groups'])->pluck('name');

        $this->assertTrue($names->contains('Erbengemeinschaft'));
        $this->assertTrue($names->contains("Gruppe von {$member->name}"));
        $this->assertFalse($names->contains('Fremde Gruppe'));
    }

    // KI-generiert
    public function test_a_plain_member_gets_the_data_back_but_no_memberships(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $shared = $this->sharedGroup($owner);
        $shared->users()->attach($member->id, ['role' => 'user']);
        $path = app(BackupService::class)->export($member);

        $summary = app(BackupService::class)->import($member, $path);
        unlink($path);

        $copy = Group::where('name', 'Erbengemeinschaft')->where('id', '!=', $shared->id)->firstOrFail();
        $this->assertSame($member->id, $copy->owner_id);
        $this->assertSame([$member->id], $copy->users()->pluck('users.id')->all());
        $this->assertSame('admin', $copy->users()->first()->pivot->role);
        $this->assertTrue(ManagementGroup::where('user_group_id', $copy->id)->where('description', 'VG Erbengemeinschaft')->exists());
        $this->assertTrue(Tenant::where('user_group_id', $copy->id)->where('last_name', 'Muster')->exists());
        $this->assertStringNotContainsString('übersprungen', $summary);
    }

    // KI-generiert
    public function test_a_group_admin_gets_existing_members_back_with_their_roles(): void
    {
        $admin = User::factory()->create();
        $colleague = User::factory()->create();
        $helper = User::factory()->create();

        $this->importAs($admin, $this->backupFile([[
            'name' => 'Team',
            'members' => [
                ['email' => strtoupper($admin->email), 'role' => 'admin'],
                ['email' => $colleague->email, 'role' => 'admin'],
                ['email' => $helper->email, 'role' => 'superuser'],
                ['name' => 'ohne Adresse'],
            ],
        ]]));

        $team = Group::where('name', 'Team')->firstOrFail();
        $roles = $team->users()->get()->mapWithKeys(fn (User $u) => [$u->id => $u->pivot->role]);
        $this->assertSame('admin', $roles[$admin->id]);
        $this->assertSame('admin', $roles[$colleague->id]);
        $this->assertSame('user', $roles[$helper->id], 'unbekannte Rolle wird zu "user"');
    }

    // KI-generiert
    public function test_a_crafted_user_backup_creates_no_accounts_and_no_foreign_owner(): void
    {
        $attacker = User::factory()->create();
        $victim = User::factory()->create();

        $summary = $this->importAs($attacker, $this->backupFile([[
            'name' => 'Übernahme',
            'owner_email' => $victim->email,
            'members' => [
                ['email' => $attacker->email, 'role' => 'admin'],
                ['email' => self::ADMIN_EMAIL, 'role' => 'admin'],
                ['email' => 'neu@hausverwaltung.test', 'role' => 'user'],
            ],
        ]]));

        $this->assertNull(User::where('email', self::ADMIN_EMAIL)->first());
        $this->assertNull(User::where('email', 'neu@hausverwaltung.test')->first());
        $this->assertSame($attacker->id, Group::where('name', 'Übernahme')->value('owner_id'));
        $this->assertStringContainsString('2 Mitglied(er) ohne Konto', $summary);
    }

    // KI-generiert
    public function test_a_backup_of_someone_else_restores_data_only(): void
    {
        $importer = User::factory()->create();
        $other = User::factory()->create();

        $this->importAs($importer, $this->backupFile([[
            'name' => 'Fremdes Backup',
            'members' => [['email' => $other->email, 'role' => 'admin']],
        ]]));

        $group = Group::where('name', 'Fremdes Backup')->firstOrFail();
        $this->assertSame([$importer->id], $group->users()->pluck('users.id')->all());
    }

    // KI-generiert
    public function test_the_administrator_importing_an_own_user_backup_is_member_of_the_restored_group(): void
    {
        $administrator = User::factory()->create(['email' => self::ADMIN_EMAIL]);

        $this->importAs($administrator, $this->backupFile([['name' => 'Eigene Gruppe', 'members' => []]]));

        $group = Group::where('name', 'Eigene Gruppe')->firstOrFail();
        $this->assertSame('admin', $group->users()->where('users.id', $administrator->id)->first()->pivot->role);
    }

    // KI-generiert
    public function test_the_full_import_still_recreates_missing_accounts(): void
    {
        $administrator = User::factory()->create(['email' => self::ADMIN_EMAIL]);

        $this->importAs($administrator, $this->backupFile([[
            'name' => 'Wiederhergestellt',
            'owner_email' => 'besitzer@hausverwaltung.test',
            'members' => [['email' => 'besitzer@hausverwaltung.test', 'role' => 'admin']],
        ]], 'full'));

        $owner = User::where('email', 'besitzer@hausverwaltung.test')->firstOrFail();
        $this->assertTrue($owner->must_change_password);
        $this->assertSame($owner->id, Group::where('name', 'Wiederhergestellt')->value('owner_id'));
    }

    // KI-generiert
    public function test_the_api_exports_and_imports_the_visible_data_of_a_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->sharedGroup($owner)->users()->attach($member->id, ['role' => 'user']);
        Sanctum::actingAs($member);

        $download = $this->get(route('api.backup.export'))->assertOk();
        $zip = new ZipArchive;
        $zip->open($download->getFile()->getPathname());
        $this->assertStringContainsString('Erbengemeinschaft', (string) $zip->getFromName('backup.json'));
        $zip->close();

        $upload = new UploadedFile($download->getFile()->getPathname(), 'backup.zip', 'application/zip', null, true);
        $this->post(route('api.backup.import'), ['backup' => $upload], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(2, Group::where('name', 'Erbengemeinschaft')->count());
    }
}
