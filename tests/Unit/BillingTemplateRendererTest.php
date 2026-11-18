<?php

namespace Tests\Unit;

use App\Services\BillingTemplateRenderer;
use Tests\TestCase;

/**
 * Regression: a header/footer overlaid via "position: fixed" repeats correctly on
 * every PDF page (within the page margins DomPDF reserves for it), but the exact
 * same CSS just covers the top/bottom of the content in a plain HTML preview, since
 * a browser has no concept of "page margins" reserving space for it there. Fixed by
 * having the renderer also emit a small script that pads the body to match the
 * header/footer's actual rendered height – see BillingTemplateRenderer::render().
 */
class BillingTemplateRendererTest extends TestCase
{
    public function test_a_header_is_overlaid_with_a_script_that_reserves_space_for_it(): void
    {
        $html = (new BillingTemplateRenderer)->render(
            '<html><body>Inhalt</body></html>', '<p>Kopf</p>', null, []
        );

        $this->assertStringContainsString('id="__billing-template-header"', $html);
        $this->assertStringContainsString('<p>Kopf</p>', $html);
        $this->assertStringContainsString('reserveSpace', $html);
        $this->assertStringContainsString("addEventListener('load'", $html);
    }

    public function test_a_footer_is_overlaid_with_a_script_that_reserves_space_for_it(): void
    {
        $html = (new BillingTemplateRenderer)->render(
            '<html><body>Inhalt</body></html>', null, '<p>Fuß</p>', []
        );

        $this->assertStringContainsString('id="__billing-template-footer"', $html);
        $this->assertStringContainsString('<p>Fuß</p>', $html);
        $this->assertStringContainsString('reserveSpace', $html);
    }

    public function test_the_reserve_space_script_appears_only_once_when_both_header_and_footer_are_given(): void
    {
        $html = (new BillingTemplateRenderer)->render(
            '<html><body>Inhalt</body></html>', '<p>Kopf</p>', '<p>Fuß</p>', []
        );

        $this->assertSame(1, substr_count($html, '<script>'));
        $this->assertStringContainsString('id="__billing-template-header"', $html);
        $this->assertStringContainsString('id="__billing-template-footer"', $html);
    }

    public function test_content_without_a_header_or_footer_is_returned_unchanged(): void
    {
        $html = (new BillingTemplateRenderer)->render(
            '<html><body>Nur Inhalt</body></html>', null, null, []
        );

        $this->assertSame('<html><body>Nur Inhalt</body></html>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_a_header_is_prepended_when_the_content_has_no_body_tag(): void
    {
        $html = (new BillingTemplateRenderer)->render('Nur ein Fragment', '<p>Kopf</p>', null, []);

        $this->assertStringStartsWith('<div id="__billing-template-header"', $html);
        $this->assertStringContainsString('Nur ein Fragment', $html);
    }

    /**
     * Konzept.md "### Template": "Der Content soll von der Formatierung getrennt
     * sein ... Beim Erstellen der Betriebskostenabrechnung muss dann der Content und
     * die Formatierung zusammen gebracht werden." – die separate CSS wird beim
     * HTML-Preview in den <head> eingefügt.
     */
    public function test_css_content_is_injected_into_the_head(): void
    {
        $html = (new BillingTemplateRenderer)->render(
            '<html><head></head><body>Inhalt</body></html>', null, null, [], 'body { color: red; }'
        );

        $this->assertStringContainsString('<style>body { color: red; }</style></head>', $html);
    }

    public function test_css_content_is_prepended_when_the_content_has_no_head_tag(): void
    {
        $html = (new BillingTemplateRenderer)->render('Nur ein Fragment', null, null, [], 'body { color: red; }');

        $this->assertStringStartsWith('<style>body { color: red; }</style>', $html);
    }

    public function test_css_content_is_rendered_with_blade(): void
    {
        $html = (new BillingTemplateRenderer)->render(
            '<html><head></head><body>Inhalt</body></html>', null, null, ['farbe' => 'blue'], 'body { color: {{ $farbe }}; }'
        );

        $this->assertStringContainsString('body { color: blue; }', $html);
    }

    public function test_content_without_css_content_is_unaffected(): void
    {
        $html = (new BillingTemplateRenderer)->render(
            '<html><head></head><body>Inhalt</body></html>', null, null, []
        );

        $this->assertStringNotContainsString('<style>', $html);
    }
}
