<?php

// KI-generiert

namespace Tests\Unit\Files;

use App\Support\FileStorage;
use App\Support\LegacyFileMover;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Umzug der früher öffentlich abgelegten Dateien auf die nicht öffentliche Disk.
 */
class LegacyFileMoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(FileStorage::diskName());
        Storage::fake((string) config('files.legacy_disk'));
    }

    private function legacy(): Filesystem
    {
        return Storage::disk((string) config('files.legacy_disk'));
    }

    public function test_files_of_known_directories_move_and_others_stay(): void
    {
        $this->legacy()->put('invoices/a.pdf', 'A');
        $this->legacy()->put('lease-documents/b.pdf', 'B');
        $this->legacy()->put('anderes/c.txt', 'C');
        FileStorage::disk()->put('invoices/schon-da.pdf', 'NEU');
        $this->legacy()->put('invoices/schon-da.pdf', 'ALT');

        $moved = app(LegacyFileMover::class)->toPrivate();

        $this->assertSame(2, $moved);
        $this->assertSame('A', FileStorage::disk()->get('invoices/a.pdf'));
        $this->assertSame('B', FileStorage::disk()->get('lease-documents/b.pdf'));
        $this->assertSame('NEU', FileStorage::disk()->get('invoices/schon-da.pdf'));
        $this->legacy()->assertMissing('invoices/a.pdf');
        $this->legacy()->assertMissing('invoices/schon-da.pdf');
        $this->legacy()->assertExists('anderes/c.txt');
    }

    public function test_the_move_can_be_reversed(): void
    {
        FileStorage::disk()->put('billing-template-images/k.png', 'K');

        $this->assertSame(1, app(LegacyFileMover::class)->toLegacy());
        $this->assertSame('K', $this->legacy()->get('billing-template-images/k.png'));
        FileStorage::disk()->assertMissing('billing-template-images/k.png');
    }

    public function test_nothing_moves_when_both_disks_are_the_same(): void
    {
        config(['files.legacy_disk' => FileStorage::diskName()]);
        FileStorage::disk()->put('invoices/a.pdf', 'A');

        $this->assertSame(0, app(LegacyFileMover::class)->toPrivate());
        FileStorage::disk()->assertExists('invoices/a.pdf');
    }
}
