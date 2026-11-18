<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixture for the TestDataSeeder{Structure,Contacts,Leases,InvoicesAndCosts,
 * Templates}Test classes (split out of one 2600+ line TestDataSeederTest, mirroring
 * TestDataSeeder's own Concerns/* traits): points the seeder (config seeding.data_file)
 * at a seed file in a temporary directory of its own for each test, so the real,
 * private seed data in "private-assets/" is never touched.
 */
abstract class TestDataSeederTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $path;

    /**
     * Eigener, temporärer Ordner je Test: Die Seed-Datei und ihre "From-File"-Dateien
     * liegen dort statt in "private-assets/" – die echten (privaten) Seed-Daten werden
     * nie angefasst (KI-generiert).
     */
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/hv-seed-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->path = $this->directory.'/seeddata.yaml';
        config(['seeding.data_file' => $this->path]);
    }

    /**
     * Pfad einer weiteren Datei neben der Seed-Datei (für "From-File"-Verweise).
     */
    protected function fixturePath(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);

        parent::tearDown();
    }
}
