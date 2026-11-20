<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Screenshots der Bedienungsanleitung: Erzeugung mit Beispieldaten (ohne echte Daten
 * anzufassen) und Einbindung in /help und die statische Anleitung.
 */
class DocumentationScreenshotsTest extends TestCase
{
    use RefreshDatabase;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = storage_path('framework/testing/docs-'.uniqid());
        config(['docs.work_directory' => $this->work, 'docs.screenshot_directory' => $this->work.'/bilder', 'docs.chromium' => 'chromium-test']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        parent::tearDown();
    }

    public function test_the_command_renders_every_page_with_demo_data_and_leaves_the_real_data_alone(): void
    {
        Process::fake();
        $connection = DB::getDefaultConnection();
        $filesDisk = config('files.disk');

        $this->artisan('docs:screenshots')->assertSuccessful();

        Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === 'chromium-test' && str_contains(implode(' ', $process->command), '--screenshot='.$this->work.'/bilder/'), 9);
        $this->assertStringContainsString('Musterstraße 1', File::get($this->work.'/verwaltungsgruppe.html'));
        $this->assertStringContainsString('Hausmeister Winterdienst', File::get($this->work.'/nicht-zugeordnet.html'));
        $this->assertStringNotContainsString(rtrim(url('/'), '/').'/build/', File::get($this->work.'/dashboard.html'));

        $this->assertSame($connection, DB::getDefaultConnection());
        $this->assertSame($filesDisk, config('files.disk'));
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_a_failing_browser_stops_the_command(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'kein Display', exitCode: 1)]);

        $this->expectExceptionMessage('Chromium konnte dashboard nicht aufnehmen: kein Display');
        $this->artisan('docs:screenshots');
    }

    public function test_the_help_page_embeds_the_screenshots_and_serves_only_png_files_from_their_directory(): void
    {
        File::ensureDirectoryExists($this->work.'/bilder');
        File::put($this->work.'/bilder/dashboard.png', 'PNG');
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('help'))->assertOk()
            ->assertSee('src="'.route('help.screenshot', ['file' => 'dashboard.png']).'"', false);
        $this->actingAs($user)->get(route('help.screenshot', ['file' => 'dashboard.png']))->assertOk();
        $this->actingAs($user)->get(route('help.screenshot', ['file' => 'fehlt.png']))->assertNotFound();
        $this->actingAs($user)->get(route('help.screenshot', ['file' => 'anleitung.md']))->assertNotFound();
        $this->actingAs($user)->get('/help/screenshots/..%2Fanleitung.md')->assertNotFound();
    }

    public function test_the_static_manual_gets_its_screenshots_next_to_it(): void
    {
        File::ensureDirectoryExists($this->work.'/bilder');
        File::put($this->work.'/bilder/dashboard.png', 'PNG');

        $this->artisan('docs:generate')->assertSuccessful();

        $this->assertFileExists(public_path('docs/screenshots/dashboard.png'));
        $this->assertStringContainsString('src="screenshots/dashboard.png"', File::get(public_path('docs/anleitung.html')));

        // Die echte Anleitung samt echten Bildern wiederherstellen.
        File::deleteDirectory(public_path('docs/screenshots'));
        config(['docs.screenshot_directory' => resource_path('docs/screenshots')]);
        $this->artisan('docs:generate');
    }
}
