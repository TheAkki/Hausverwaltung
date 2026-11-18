<?php

namespace App\Services;

/**
 * "@contentStart('75mm')" in einer Abrechnungsvorlage (Konzept.md "#### Template" c):
 * der folgende Inhalt beginnt auf seiner Seite genau so weit unter der Blattoberkante –
 * unabhängig von der Höhe der Kopfzeile und für jeden Brief der Gesamt-PDF.
 *
 * Der Blade-Befehl hinterlässt eine Markierung (marker()); BillingTemplateRenderer
 * ersetzt sie im PDF durch einen Abstand bis zur gewünschten Stelle (forPdf()) und in
 * der HTML-Vorschau durch einen Abstand abzüglich der Kopfzeilenhöhe (forPreview()).
 */
final class ContentStart
{
    private const PATTERN = '/<div data-content-start="([\d.]+)"><\/div>/';

    private const MILLIMETRES = '/^\s*(\d+(?:\.\d+)?)\s*(mm)?\s*$/i';

    private const GAP_PRECISION = 2;

    /**
     * HTML-Markierung für "@contentStart(...)"; erlaubt sind Millimeter, z. B. '75mm' oder 75.
     *
     * @throws \InvalidArgumentException bei einer Angabe, die keine Millimeter sind
     */
    public static function marker(string|int|float $start): string
    {
        if (preg_match(self::MILLIMETRES, (string) $start, $match) !== 1) {
            throw new \InvalidArgumentException("@contentStart erwartet Millimeter, z. B. '75mm' – nicht \"{$start}\".");
        }

        return '<div data-content-start="'.(float) $match[1].'"></div>';
    }

    public static function isUsedIn(string $html): bool
    {
        return preg_match(self::PATTERN, $html) === 1;
    }

    /**
     * Ersetzt jede Markierung durch einen Abstand bis zur gewünschten Stelle; beginnt der
     * Inhalt ($contentTop, mm unter der Blattoberkante) dort bereits tiefer, entfällt er.
     */
    public static function forPdf(string $html, float $contentTop): string
    {
        return preg_replace_callback(self::PATTERN, function (array $match) use ($contentTop): string {
            $gap = round((float) $match[1] - $contentTop, self::GAP_PRECISION);

            return $gap > 0 ? '<div style="height: '.$gap.'mm; margin: 0; padding: 0;"></div>' : '';
        }, $html);
    }

    /**
     * HTML-Vorschau (ohne Seiten): Abstand abzüglich der Kopfzeilenhöhe, die die Vorschau
     * als CSS-Variable "--hv-header-height" setzt.
     */
    public static function forPreview(string $html): string
    {
        return preg_replace(
            self::PATTERN,
            '<div style="height: calc($1mm - var(--hv-header-height, 0px)); margin: 0; padding: 0;"></div>',
            $html
        );
    }
}
