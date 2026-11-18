<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Covers the "/locale/{locale}" switcher (session persistence, App::getLocale(), rejection
 * of unsupported locales, actually changing rendered text) as well as the completeness of
 * "lang/en.json"/"lang/de.json": every literal string passed to __()/@lang() anywhere in
 * app/resources/routes must have an English translation, so switching to English never
 * silently leaves raw German text on the page. Mirrors the project's general "keine stillen
 * Fehler" philosophy (see TestDataSeeder) applied to translations instead of seed data.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_locale_persists_it_in_the_session_and_sets_the_app_locale(): void
    {
        $this->get(route('locale.switch', 'en'));

        $this->assertSame('en', session('locale'));

        // A fresh request re-applies SetLocale from the session.
        $this->get('/');
        $this->assertSame('en', App::getLocale());
    }

    public function test_an_unsupported_locale_is_rejected(): void
    {
        $response = $this->get(route('locale.switch', 'fr'));

        $response->assertNotFound();
        $this->assertNull(session('locale'));
    }

    public function test_the_default_locale_is_german(): void
    {
        $this->get('/');

        $this->assertSame('de', App::getLocale());
    }

    public function test_switching_to_english_changes_the_rendered_text_on_a_public_page(): void
    {
        $german = 'Verwaltung von Immobilien, Mietverträgen und Betriebskostenabrechnungen.';
        $english = __($german, [], 'en');

        // Sanity check for the test itself: if this ever matched the German source (e.g. the
        // translation got deleted), the test below would trivially "pass" without proving
        // anything, so fail loudly here instead.
        $this->assertNotSame($german, $english, 'lang/en.json is missing its translation for the welcome page text used by this test.');

        $this->get('/')->assertSee($german);

        $this->get(route('locale.switch', 'en'));
        $this->get('/')
            ->assertSee($english)
            ->assertDontSee($german);
    }

    public function test_switching_back_to_german_restores_the_german_text(): void
    {
        $this->get(route('locale.switch', 'en'));
        $this->get(route('locale.switch', 'de'));

        $this->get('/')->assertSee('Verwaltung von Immobilien, Mietverträgen und Betriebskostenabrechnungen.');
    }

    public function test_the_navigation_bar_is_translated_when_logged_in_with_english_selected(): void
    {
        $user = User::factory()->create();

        $this->get(route('locale.switch', 'en'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee(__('Verwaltungsgruppen', [], 'en'))
            ->assertDontSee('Verwaltungsgruppen', false);
    }

    /**
     * @return array<string, array<int, string>> translation key => list of "file:line" locations
     */
    private function translatableKeysUsedInTheApplication(): array
    {
        $files = collect(['app', 'resources', 'routes'])
            ->flatMap(fn (string $dir) => File::allFiles(base_path($dir)))
            ->filter(fn ($file) => \in_array($file->getExtension(), ['php'], true))
            ->values();

        // Matches __('...') / __("...") / @lang('...') / @lang("...") and captures the first
        // (literal string) argument, tolerating escaped quotes within it.
        $pattern = '/(?:__|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/';

        $keysWithLocations = [];

        foreach ($files as $file) {
            $content = $file->getContents();

            if (! preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                // Whichever alternative (single- or double-quoted) matched has a real offset
                // (>= 0); the other is the unmatched-group placeholder ['', -1].
                $key = $match[1][1] !== -1
                    ? str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1][0])
                    : str_replace(['\\"', '\\\\'], ['"', '\\'], $match[2][0]);

                // A dotted "group.item" reference (e.g. "auth.password") resolves against the
                // array-based lang/{locale}/*.php files, never against the JSON translations –
                // Laravel's translator branches on "does the key contain a dot" before even
                // looking at the JSON files. Excluding these avoids false positives here.
                if (preg_match('/^[a-z_]+(\.[a-zA-Z0-9_]+)+$/', $key) === 1) {
                    continue;
                }

                $line = substr_count($content, "\n", 0, $match[0][1]) + 1;
                $relativePath = str_replace(base_path().'/', '', $file->getPathname());
                $keysWithLocations[$key][] = "{$relativePath}:{$line}";
            }
        }

        return $keysWithLocations;
    }

    public function test_every_translatable_string_used_in_the_application_has_an_english_translation(): void
    {
        $used = $this->translatableKeysUsedInTheApplication();
        $en = json_decode(File::get(base_path('lang/en.json')), true, flags: JSON_THROW_ON_ERROR);

        $missing = array_diff(array_keys($used), array_keys($en));

        if ($missing === []) {
            $this->assertTrue(true);

            return;
        }

        $details = collect($missing)
            ->sort()
            ->map(fn (string $key) => sprintf('  "%s" (%s)', $key, implode(', ', $used[$key])))
            ->implode("\n");

        $this->fail('lang/en.json is missing a translation for '.count($missing)." string(s) used via __()/@lang(). Switching to English would silently show the raw German text for these:\n{$details}");
    }

    /**
     * Complements the "missing" check above: an entry left over in lang/de.json or
     * lang/en.json after its __()/@lang() call was renamed or removed from the codebase is
     * silently dead – exactly what happened before this test existed, with two stale
     * lang/de.json entries ("Update your account" / "You") that no longer matched the real
     * call ("Update your account's profile information and email address.") at all.
     */
    public function test_lang_json_files_have_no_stale_entries_no_longer_used_anywhere(): void
    {
        $used = array_keys($this->translatableKeysUsedInTheApplication());

        foreach (['lang/de.json', 'lang/en.json'] as $path) {
            $translations = json_decode(File::get(base_path($path)), true, flags: JSON_THROW_ON_ERROR);
            $stale = array_diff(array_keys($translations), $used);

            $this->assertSame(
                [],
                array_values($stale),
                "{$path} has stale translation(s) that no longer match any __()/@lang() call in the codebase: ".implode(', ', array_map(fn ($k) => "\"{$k}\"", $stale))
            );
        }
    }

    public function test_lang_json_files_are_valid_and_have_no_empty_translations(): void
    {
        foreach (['lang/de.json', 'lang/en.json'] as $path) {
            $translations = json_decode(File::get(base_path($path)), true);

            $this->assertIsArray($translations, "{$path} is not valid JSON.");

            foreach ($translations as $key => $value) {
                $this->assertNotSame('', trim((string) $value), "{$path}: translation for \"{$key}\" is empty.");
            }
        }
    }

    public function test_supported_locales_constant_matches_the_available_lang_json_files(): void
    {
        $availableJsonLocales = collect(File::files(base_path('lang')))
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->sort()
            ->values()
            ->all();

        $supported = SetLocale::SUPPORTED_LOCALES;
        sort($supported);

        $this->assertSame($availableJsonLocales, $supported);
    }
}
