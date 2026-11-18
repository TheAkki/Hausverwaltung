<?php

// KI-generiert

namespace Tests\Unit;

use App\Services\CanonicalUrl;
use Tests\TestCase;

/**
 * Mit config('app.force_url') entstehen alle Links und Weiterleitungen aus APP_URL
 * (Docker: nginx reicht den Port nicht an PHP weiter).
 */
class CanonicalUrlTest extends TestCase
{
    private function apply(bool $force, string $url): void
    {
        config(['app.force_url' => $force, 'app.url' => $url]);
        (new CanonicalUrl)->apply();
    }

    // KI-generiert
    public function test_links_keep_the_port_from_app_url(): void
    {
        $this->apply(true, 'http://localhost:8080');

        $this->assertSame('http://localhost:8080/login', route('login'));
        $this->assertSame('http://localhost:8080/up', url('/up'));
    }

    // KI-generiert
    public function test_an_https_app_url_forces_https_links(): void
    {
        $this->apply(true, 'HTTPS://hv.example.test/');

        $this->assertSame('https://hv.example.test/login', route('login'));
    }

    // KI-generiert
    public function test_redirects_use_app_url(): void
    {
        $this->apply(true, 'http://localhost:8080');

        $this->get('/dashboard')->assertRedirect('http://localhost:8080/login');
    }

    // KI-generiert
    public function test_without_the_setting_nothing_is_forced(): void
    {
        $before = route('login');

        $this->apply(false, 'http://localhost:8080');

        $this->assertSame($before, route('login'));
        $this->get('/dashboard')->assertRedirect($before);
    }

    // KI-generiert
    public function test_an_empty_app_url_changes_nothing(): void
    {
        $before = route('login');

        $this->apply(true, '  ');

        $this->assertSame($before, route('login'));
    }
}
