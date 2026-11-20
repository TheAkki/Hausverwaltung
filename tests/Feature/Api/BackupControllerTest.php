<?php

namespace Tests\Feature\Api;

use App\Models\ManagementGroup;
use App\Models\User;
use App\Services\BackupService;
use App\Support\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;
use ZipArchive;

// KI-generiert
class BackupControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private const ADMIN_EMAIL = 'admin@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(FileStorage::diskName());
        config(['app.admin_email' => self::ADMIN_EMAIL]);
    }

    private function backupJson(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $data = json_decode((string) $zip->getFromName('backup.json'), true);
        $zip->close();

        return $data;
    }

    public function test_the_own_backup_can_be_downloaded_as_zip(): void
    {
        $user = $this->apiUser();
        $this->makeManagementGroup($user);

        $response = $this->get(route('api.backup.export'))->assertOk()->assertDownload();

        $data = $this->backupJson($response->getFile()->getPathname());
        $this->assertSame(BackupService::VERSION, $data['version']);
        $this->assertSame('MG', $data['groups'][0]['management_groups'][0]['description']);
    }

    public function test_only_the_administrator_may_download_the_full_backup(): void
    {
        $this->apiUser();
        $this->getJson(route('api.backup.export-full'))->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['email' => self::ADMIN_EMAIL]));
        $response = $this->get(route('api.backup.export-full'))->assertOk()->assertDownload();
        $this->assertSame('full', $this->backupJson($response->getFile()->getPathname())['type']);
    }

    public function test_a_backup_can_be_restored(): void
    {
        $user = $this->apiUser();
        $this->makeManagementGroup($user);
        $path = app(BackupService::class)->export($user->fresh());

        $this->post(route('api.backup.import'), [
            'backup' => new UploadedFile($path, 'backup.zip', 'application/zip', null, true),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonStructure(['message']);

        $this->assertSame(2, ManagementGroup::where('description', 'MG')->count());
    }

    public function test_an_invalid_archive_is_rejected_with_a_validation_error(): void
    {
        $this->apiUser();
        $path = tempnam(sys_get_temp_dir(), 'hv-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('backup.json', json_encode(['format' => 'fremd']));
        $zip->close();

        $this->post(route('api.backup.import'), [
            'backup' => new UploadedFile($path, 'backup.zip', 'application/zip', null, true),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('backup');

        $this->postJson(route('api.backup.import'), [])->assertUnprocessable()->assertJsonValidationErrors('backup');
    }
}
