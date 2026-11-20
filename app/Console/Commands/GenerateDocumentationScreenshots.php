<?php

namespace App\Console\Commands;

use App\Models\BillingInterval;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\DocumentationDemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Erzeugt die Screenshots der Bedienungsanleitung (Konzept.md "### Bedienungsanleitung":
 * "In dieser sollen auch Screenshots enthalten sein") aus frei erfundenen Beispieldaten.
 *
 * Alles läuft auf einer eigenen SQLite-Datenbank im Speicher und einer eigenen
 * Datei-Disk – die echten Daten werden weder gelesen noch verändert. Die Bilder
 * landen in resources/docs/screenshots und werden mit eingecheckt; docs:generate und
 * /help binden sie ein.
 */
#[Signature('docs:screenshots')]
#[Description('Erzeugt die Screenshots der Bedienungsanleitung aus Beispieldaten (braucht Chromium)')]
class GenerateDocumentationScreenshots extends Command
{
    private const CONNECTION = 'docs_demo';

    private const DISK = 'docs_demo';

    /** Seite => Route und Parameter (aus den Beispieldaten). */
    private const PAGES = [
        'dashboard' => 'dashboard',
        'verwaltungsgruppe' => 'management-groups.show',
        'mietvertraege' => 'leases.index',
        'mietvertrag' => 'leases.show',
        'rechnungen' => 'invoices.index',
        'rechnung-anlegen' => 'invoices.create',
        'offene-zahlungen' => 'open-payments.index',
        'nicht-zugeordnet' => 'unassigned-bookings.index',
        'betriebskostenabrechnung' => 'billing-statements.show-lease',
    ];

    public function handle(): int
    {
        $previous = ['default' => config('database.default'), 'files' => config('files'), 'now' => Carbon::getTestNow()];

        try {
            $this->isolate();
            $user = User::where('email', DocumentationDemoSeeder::EMAIL)->sole();
            $this->laravel['auth']->guard('web')->setUser($user);

            foreach (self::PAGES as $name => $route) {
                $this->capture($name, route($route, $this->parameters($route)));
            }
        } finally {
            $this->restore($previous);
        }

        $this->info('Screenshots erzeugt: '.config('docs.screenshot_directory'));

        return self::SUCCESS;
    }

    private function isolate(): void
    {
        $work = (string) config('docs.work_directory');
        File::ensureDirectoryExists($work);

        config([
            'database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => self::CONNECTION,
            'filesystems.disks.'.self::DISK => ['driver' => 'local', 'root' => $work.'/files'],
            'files.disk' => self::DISK,
            'files.legacy_disk' => self::DISK,
        ]);
        DB::setDefaultConnection(self::CONNECTION);
        Carbon::setTestNow((string) config('docs.demo_date'));

        Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
        Artisan::call('db:seed', ['--class' => DocumentationDemoSeeder::class, '--database' => self::CONNECTION, '--force' => true]);
    }

    /**
     * @param  array{default: mixed, files: mixed, now: mixed}  $previous
     */
    private function restore(array $previous): void
    {
        $this->laravel['auth']->forgetGuards();
        DB::purge(self::CONNECTION);
        config(['database.default' => $previous['default'], 'files' => $previous['files']]);
        DB::setDefaultConnection((string) $previous['default']);
        Carbon::setTestNow($previous['now']);
    }

    /**
     * @return array<string, int>
     */
    private function parameters(string $route): array
    {
        $managementGroup = ManagementGroup::where('description', DocumentationDemoSeeder::MANAGEMENT_GROUP)->sole();

        return match ($route) {
            'management-groups.show' => ['managementGroup' => $managementGroup->id],
            'leases.show' => ['lease' => Lease::whereNull('coupled_to_lease_id')->orderBy('id')->value('id')],
            'billing-statements.show-lease' => [
                'managementUnit' => $managementGroup->managementUnits()->value('id'),
                'billingInterval' => BillingInterval::where('management_group_id', $managementGroup->id)->orderBy('start_date')->value('id'),
                'lease' => Lease::whereNull('coupled_to_lease_id')->orderBy('id')->value('id'),
            ],
            default => [],
        };
    }

    private function capture(string $name, string $url): void
    {
        $response = $this->laravel->make(Kernel::class)->handle(Request::create($url));

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("{$url} antwortet mit {$response->getStatusCode()}.");
        }

        $html = (string) config('docs.work_directory')."/{$name}.html";
        File::put($html, $this->localAssets((string) $response->getContent()));

        $png = (string) config('docs.screenshot_directory')."/{$name}.png";
        File::ensureDirectoryExists(dirname($png));

        $result = Process::timeout((int) config('docs.timeout_seconds'))->run([
            (string) config('docs.chromium'), '--headless', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
            '--window-size='.config('docs.window_width').','.config('docs.window_height'),
            '--screenshot='.$png, 'file://'.$html,
        ]);

        if (! $result->successful()) {
            throw new \RuntimeException("Chromium konnte {$name} nicht aufnehmen: ".$result->errorOutput());
        }

        $this->line("  {$name}.png");
    }

    /**
     * Stylesheets und Skripte aus public/build direkt von der Platte laden – die
     * Seite liegt als Datei vor, nicht hinter einem Webserver.
     */
    private function localAssets(string $html): string
    {
        return str_replace(rtrim(url('/'), '/').'/build/', 'file://'.public_path('build').'/', $html);
    }
}
