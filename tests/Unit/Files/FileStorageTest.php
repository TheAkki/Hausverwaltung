<?php

// KI-generiert

namespace Tests\Unit\Files;

use App\Support\FileStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class FileStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(FileStorage::diskName());
    }

    public function test_every_kind_has_a_directory_and_an_unknown_one_is_rejected(): void
    {
        foreach ([FileStorage::INVOICES, FileStorage::LEASE_DOCUMENTS, FileStorage::BILLING_TEMPLATE_IMAGES, FileStorage::SETTLEMENT_CLAIMS, FileStorage::ACCOUNT_TRANSFERS] as $kind) {
            $this->assertNotSame('', FileStorage::directory($kind));
        }

        $this->expectException(\InvalidArgumentException::class);
        FileStorage::directory('gibt-es-nicht');
    }

    public function test_store_duplicate_and_delete(): void
    {
        $path = FileStorage::store(UploadedFile::fake()->create('a.pdf', 1), FileStorage::INVOICES);
        $this->assertStringStartsWith(FileStorage::directory(FileStorage::INVOICES).'/', $path);

        $copy = FileStorage::duplicate($path);
        $this->assertNotSame($path, $copy);
        $this->assertSame(FileStorage::disk()->get($path), FileStorage::disk()->get($copy));
        $this->assertSame('pdf', pathinfo($copy, PATHINFO_EXTENSION));

        FileStorage::delete($path);
        FileStorage::delete(null);
        FileStorage::disk()->assertMissing($path);
        FileStorage::disk()->assertExists($copy);

        $this->assertNull(FileStorage::duplicate(null));
        $this->assertNull(FileStorage::duplicate('invoices/weg.pdf'));
    }

    public function test_a_file_without_extension_is_duplicated_without_one(): void
    {
        FileStorage::disk()->put('invoices/ohne', 'x');

        $this->assertSame('', pathinfo(FileStorage::duplicate('invoices/ohne'), PATHINFO_EXTENSION));
    }

    public function test_the_upload_rules_name_size_and_types(): void
    {
        $this->assertSame(['file', 'max:'.FileStorage::MAX_KILOBYTES, 'mimes:'.implode(',', FileStorage::ALLOWED_EXTENSIONS)], FileStorage::uploadRules());
    }

    public function test_a_file_that_cannot_be_stored_is_an_error(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->andReturn(false);
        Storage::set(FileStorage::diskName(), $disk);

        $this->expectExceptionMessage('Die Datei konnte nicht gespeichert werden.');
        FileStorage::store(UploadedFile::fake()->create('a.pdf', 1), FileStorage::INVOICES);
    }

    public function test_a_response_for_a_missing_file_is_not_found(): void
    {
        $this->expectException(NotFoundHttpException::class);
        FileStorage::response(null, 'x.pdf');
    }
}
