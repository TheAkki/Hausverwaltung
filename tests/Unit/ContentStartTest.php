<?php

// KI-generiert

namespace Tests\Unit;

use App\Services\BillingTemplateRenderer;
use App\Services\ContentStart;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentStartTest extends TestCase
{
    /**
     * @return array<string, array{string|int|float, string}>
     */
    public static function starts(): array
    {
        return [
            'mit Einheit' => ['75mm', '75'],
            'Zahl' => [75, '75'],
            'Dezimal mit Leerzeichen' => [' 12.5 mm ', '12.5'],
            'Großbuchstaben' => ['40MM', '40'],
        ];
    }

    // KI-generiert
    #[DataProvider('starts')]
    public function test_the_marker_accepts_millimetres(string|int|float $start, string $expected): void
    {
        $this->assertSame('<div data-content-start="'.$expected.'"></div>', ContentStart::marker($start));
    }

    // KI-generiert
    public function test_other_units_are_rejected_with_a_hint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("@contentStart erwartet Millimeter, z. B. '75mm' – nicht \"3cm\".");

        ContentStart::marker('3cm');
    }

    // KI-generiert
    public function test_the_pdf_gets_the_gap_down_to_the_requested_position(): void
    {
        $html = 'A'.ContentStart::marker(75).'B'.ContentStart::marker(40).'C';

        $this->assertTrue(ContentStart::isUsedIn($html));
        $this->assertSame('A<div style="height: 25mm; margin: 0; padding: 0;"></div>BC', ContentStart::forPdf($html, 50.0));
        $this->assertFalse(ContentStart::isUsedIn('<div>ohne</div>'));
    }

    // KI-generiert
    public function test_the_preview_subtracts_the_header_height(): void
    {
        $this->assertSame(
            '<div style="height: calc(75mm - var(--hv-header-height, 0px)); margin: 0; padding: 0;"></div>',
            ContentStart::forPreview(ContentStart::marker('75mm'))
        );
    }

    // KI-generiert
    public function test_the_blade_directive_writes_the_marker_and_reports_wrong_units(): void
    {
        $this->assertSame('x <div data-content-start="75"></div> y', Blade::render("x @contentStart('75mm') y"));

        $this->expectException(ViewException::class);
        Blade::render("@contentStart('3cm')");
    }

    // KI-generiert
    public function test_preview_and_pdf_of_a_template_using_the_directive(): void
    {
        $renderer = new BillingTemplateRenderer;
        $content = "<html><head><style>@page { margin: 50mm 20mm 20mm 25mm; }</style></head><body>@contentStart('75mm')<p>Start</p></body></html>";
        $tallHeader = '<div style="height: 60mm">Kopf</div>';

        $preview = $renderer->render($content, $tallHeader, null, []);
        $this->assertStringContainsString('calc(75mm - var(--hv-header-height, 0px))', $preview);
        $this->assertStringContainsString("setProperty('--hv-header-height'", $preview);

        $this->assertStringStartsWith('%PDF', $renderer->renderPdf($content, $tallHeader, null, []));
        $this->assertStringStartsWith('%PDF', $renderer->renderPdf($content, null, null, []));
    }
}
