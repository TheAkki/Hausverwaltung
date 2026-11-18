<?php

// KI-generiert

namespace Tests\Unit;

use App\Services\BillingTemplateRenderer;
use Tests\TestCase;

/**
 * Maße in mm müssen auf Papier stimmen (Anschriftfeld für Fensterumschläge): das PDF
 * setzt absolut positionierte Container exakt und gibt dem Betrachter vor, beim Drucken
 * nicht zu skalieren.
 */
class BillingTemplatePrintGeometryTest extends TestCase
{
    private const POINTS_PER_MM = 72 / 25.4;

    private const A4_HEIGHT_PT = 841.89;

    private function pdf(string $css): string
    {
        return (new BillingTemplateRenderer)->renderPdf(
            '<html><body><div class="window">Anschrift</div><p>Text</p></body></html>',
            '<div>Kopf</div>',
            '<div>Fuß</div>',
            [],
            $css
        );
    }

    /** Entpackte Inhalts-Streams des PDFs. */
    private function contentStreams(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        return implode("\n", array_map(fn (string $raw) => @gzuncompress($raw) ?: $raw, $matches[1]));
    }

    private function point(float $millimetres): string
    {
        return number_format($millimetres * self::POINTS_PER_MM, 3, '.', '');
    }

    // KI-generiert
    public function test_the_pdf_asks_the_viewer_to_print_at_actual_size(): void
    {
        $this->assertStringContainsString('/PrintScaling /None', $this->pdf(''));
    }

    // KI-generiert
    public function test_an_absolute_container_sits_exactly_at_its_millimetres(): void
    {
        $streams = $this->contentStreams($this->pdf(
            '@page { margin: 50mm 20mm 20mm 25mm; } .window { position: absolute; top: 27mm; left: 20mm; width: 85mm; height: 45mm; border: 2px solid #000; }'
        ));

        $left = $this->point(20);
        $top = number_format(self::A4_HEIGHT_PT - 27 * self::POINTS_PER_MM, 3, '.', '');

        // Außenkante des Rahmens oben links: genau 20 mm von links, 27 mm von oben.
        $this->assertStringContainsString("{$left} {$top} m", $streams);
    }
}
