<?php

namespace App\Services;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Erzeugt alle Links und Weiterleitungen aus APP_URL statt aus der Anfrage, wenn
 * config('app.force_url') gesetzt ist (Docker: APP_FORCE_URL=true).
 *
 * Hintergrund: nginx in Debian reicht aus Sicherheitsgründen nur den Hostnamen
 * ohne Port an PHP weiter (HTTP_HOST = $host). Ohne diese Einstellung führten
 * Weiterleitungen etwa von http://localhost:8080 nach http://localhost/… ins
 * Leere. Hinter einem HTTPS-Reverse-Proxy sorgt sie zudem für https-Links.
 */
class CanonicalUrl
{
    private const HTTPS_SCHEME = 'https';

    public function apply(): void
    {
        if (! config('app.force_url')) {
            return;
        }

        $url = $this->normalize((string) config('app.url'));

        if ($url === '') {
            return;
        }

        URL::forceRootUrl($url);

        if (Str::startsWith($url, self::HTTPS_SCHEME.'://')) {
            URL::forceScheme(self::HTTPS_SCHEME);
        }
    }

    /**
     * Ohne abschließenden Schrägstrich, Schema klein geschrieben ("HTTPS://" → "https://").
     */
    private function normalize(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return (string) preg_replace_callback('#^[a-z][a-z0-9+.-]*://#i', fn (array $scheme) => strtolower($scheme[0]), $url);
    }
}
