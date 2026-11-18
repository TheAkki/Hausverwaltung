<?php

namespace App\Services;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a Betriebskostenabrechnung template's content/header/footer with the given
 * data. Two output modes:
 *
 * - render(): overlays the rendered header (top) and footer (bottom) as fixed-position
 *   elements inside the content's <body> (or prepended if there's none). Used for the
 *   plain-HTML preview (a browser, with no real concept of "pages") shown by
 *   BillingStatementController::show()/showLease() and BillingTemplateController::
 *   preview()/check().
 * - renderPdf(): renders the same header/content/footer as an actual paginated PDF via
 *   mPDF, using its native SetHTMLHeader()/SetHTMLFooter() – unlike the plain-HTML
 *   overlay (or DomPDF's own "position: fixed" header/footer, which this project used
 *   until it turned out to not reserve any real page space for a header/footer taller
 *   than the reserved margin, silently overlapping page content instead), mPDF treats
 *   the header/footer as genuine, space-reserving page furniture: margin_top/
 *   margin_bottom control how much room the *content* gets (and must stay clear of),
 *   while margin_header/margin_footer independently control how close the header/
 *   footer sit to the physical page edge – so a template can have a generous content
 *   margin (safe against the last line before a page break) *and* a tight header/
 *   footer-to-edge distance at the same time, which DomPDF's single shared @page
 *   margin could never express.
 */
class BillingTemplateRenderer
{
    /**
     * How close the header/footer sit to the physical page edge (top/bottom), in mm –
     * independent of the @page margin below, which instead reserves how much room the
     * *content* gets and must stay clear of. See the class doc comment.
     */
    private const EDGE_DISTANCE_MM = 5;

    /**
     * Druckvorgabe im PDF (/ViewerPreferences /PrintScaling /None): der Betrachter soll im
     * Druckdialog "Tatsächliche Größe" statt "An Seite anpassen" vorwählen. Sonst wird die
     * Seite auf den druckbaren Bereich verkleinert (typisch ~93 %) und Maße in mm – etwa
     * das Anschriftfeld für Fensterumschläge – stimmen auf Papier nicht mehr.
     */
    public const PRINT_AT_ACTUAL_SIZE = '/NoPrintScaling';

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $content, ?string $header, ?string $footer, array $data, ?string $cssContent = null): string
    {
        $html = Blade::render($content, $data);

        $renderedHeader = $header ? Blade::render($header, $data) : null;
        $renderedFooter = $footer ? Blade::render($footer, $data) : null;

        if ($renderedHeader !== null || $renderedFooter !== null) {
            $overlay = ($renderedHeader !== null ? '<div id="__billing-template-header" style="position: fixed; top: 0; left: 0; right: 0;">'.$renderedHeader.'</div>' : '')
                .($renderedFooter !== null ? '<div id="__billing-template-footer" style="position: fixed; bottom: 0; left: 0; right: 0;">'.$renderedFooter.'</div>' : '')
                .$this->reserveSpaceScript();

            $html = preg_match('/<body[^>]*>/i', $html)
                ? preg_replace('/(<body[^>]*>)/i', '$1'.$overlay, $html, 1)
                : $overlay.$html;
        }

        return $this->injectCss(ContentStart::forPreview($html), $cssContent, $data);
    }

    /**
     * Renders the same Header/Inhalt/Fußzeile as an actual PDF (raw bytes), via mPDF
     * instead of DomPDF – see the class doc comment for why.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderPdf(string $content, ?string $header, ?string $footer, array $data, ?string $cssContent = null): string
    {
        $bodyDocument = Blade::render($content, $data);
        $renderedHeader = $header ? Blade::render($header, $data) : null;
        $renderedFooter = $footer ? Blade::render($footer, $data) : null;
        $renderedCss = $cssContent ? Blade::render($cssContent, $data) : null;

        [$styleCss, $bodyOnly] = $this->splitStyleAndBody($bodyDocument);
        // Konzept.md "### Template": Content und Formatierung ("CSS-Content") sind
        // getrennt gepflegt, werden hier für das Rendern zusammengeführt – der Content
        // darf zusätzlich weiterhin sein eigenes <style> mitbringen (Altbestand/nicht
        // getrennte Templates), beides zusammen ergibt die endgültige CSS.
        $styleCss = trim($styleCss."\n".($renderedCss ?? ''));
        $margin = $this->extractPageMargin($styleCss);

        $config = $this->mpdfConfig($margin);
        $mpdf = new Mpdf($config);
        $mpdf->SetDisplayPreferences(self::PRINT_AT_ACTUAL_SIZE);

        // Die "@page { margin: ... }"-Regel selbst wird NICHT an mPDF weitergereicht,
        // obwohl extractPageMargin() sie oben gelesen hat (dieselben Werte stehen ja
        // schon als margin_top/margin_right/margin_bottom/margin_left im Konstruktor).
        // Grund: sobald mPDF irgendeine "@page"-Regel registriert (auch mit exakt
        // denselben Werten), schaltet es intern auf "Paged Media" um und wendet sie bei
        // JEDEM neuen Seitenbeginn erneut an (Mpdf::_beginpage()) – das überschreibt den
        // von setAutoTopMargin="stretch" dynamisch vergrößerten margin_top wieder mit dem
        // nominalen @page-Wert, sobald der Seitenumbruch aus einer mitten im Fluss
        // umbrechenden <table> kommt (nicht bei einem "normalen" Absatzumbruch – dort
        // greift ein anderer mPDF-Codepfad). Sichtbarer Effekt: die Kopfzeile (höher als
        // der nominale @page-margin_top, deshalb überhaupt auf "stretch" angewiesen)
        // verschwindet auf jeder Seite nach einem tabellenbedingten Umbruch. Ohne die
        // @page-Regel bleibt der gestreckte margin_top über Tabellenumbrüche hinweg
        // erhalten, mPDF bekommt dieselben Werte ja bereits über den Konstruktor.
        $styleCssForMpdf = preg_replace('/@page\s*\{[^}]*\}/i', '', $styleCss);

        if ($styleCssForMpdf !== '') {
            // HTMLParserMode::HEADER_CSS wraps $html in "<style> ... </style>" selbst
            // (siehe Mpdf::WriteHTML()) – ein zusätzliches eigenes <style>-Tag hier würde
            // verschachtelte <style>-Tags erzeugen. mPDFs Regex zum Extrahieren des
            // Stylesheet-Inhalts ist dann nicht mehr in der Lage, die öffnende Klammer der
            // ERSTEN CSS-Regel von diesem Tag-Rest zu trennen, wodurch genau diese erste
            // Regel unwirksam wird (z. B. eine dort gesetzte "background-color").
            $mpdf->WriteHTML($styleCssForMpdf, HTMLParserMode::HEADER_CSS);
        }
        if ($renderedHeader !== null) {
            $mpdf->SetHTMLHeader($renderedHeader);
        }
        if ($renderedFooter !== null) {
            $mpdf->SetHTMLFooter($renderedFooter);
        }
        if (ContentStart::isUsedIn($bodyOnly)) {
            $bodyOnly = ContentStart::forPdf($bodyOnly, $this->contentTop($config, $renderedHeader));
        }

        $mpdf->WriteHTML($bodyOnly, HTMLParserMode::HTML_BODY);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * @param  array{top: float, right: float, bottom: float, left: float}  $margin
     * @return array<string, mixed>
     */
    private function mpdfConfig(array $margin): array
    {
        return [
            'margin_top' => $margin['top'],
            'margin_right' => $margin['right'],
            'margin_bottom' => $margin['bottom'],
            'margin_left' => $margin['left'],
            // Nie größer als die jeweilige Content-Reserve selbst – sonst würde die
            // Kopf-/Fußzeile weiter von der Kante weg (und damit in Richtung Content)
            // rutschen, statt näher an ihr zu sitzen.
            'margin_header' => min(self::EDGE_DISTANCE_MM, $margin['top']),
            'margin_footer' => min(self::EDGE_DISTANCE_MM, $margin['bottom']),
            // Ist die tatsächlich gerenderte Kopf-/Fußzeile höher als der oben aus
            // @page übernommene margin_top/margin_bottom (z. B. ein Kopf-Bild plus
            // mehrzeiliger Text), schiebt mPDF den Content-Bereich automatisch weiter
            // nach unten/oben, statt dass Kopf-/Fußzeile den Inhalt überlappen –
            // andernfalls müsste jedes Template seinen @page-margin von Hand groß
            // genug für seine eigene Kopf-/Fußzeile raten.
            'setAutoTopMargin' => 'stretch',
            'setAutoBottomMargin' => 'stretch',
            'tempDir' => $this->tempDir(),
        ];
    }

    /**
     * Beschreibbares Verzeichnis für mPDFs Zwischendateien (config/pdf.php), bei
     * Bedarf angelegt – mPDFs Standard läge in vendor/ und ist im Container nicht
     * beschreibbar.
     */
    private function tempDir(): string
    {
        $directory = (string) config('pdf.temp_dir');
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    /**
     * Tatsächlicher Beginn des Inhalts unter der Blattoberkante (mm): der obere Rand,
     * von einer höheren Kopfzeile ggf. gestreckt (setAutoTopMargin "stretch") – gemessen
     * an einer Probeseite mit derselben Kopfzeile.
     *
     * @param  array<string, mixed>  $config
     */
    private function contentTop(array $config, ?string $header): float
    {
        $probe = new Mpdf($config);

        if ($header !== null) {
            $probe->SetHTMLHeader($header);
        }

        $probe->AddPage();

        return (float) $probe->tMargin;
    }

    /**
     * Merges a template's separate "CSS-Content" (Konzept.md "### Template": "Der
     * Content soll von der Formatierung getrennt sein ... Beim Erstellen der
     * Betriebskostenabrechnung muss dann der Content und die Formatierung zusammen
     * gebracht werden") into the rendered HTML's <head>, for the plain-HTML preview.
     * A template without "CSS-Content" (the common case for a not-yet-separated or
     * older template, which may still carry its own inline <style>) is returned
     * unchanged.
     *
     * @param  array<string, mixed>  $data
     */
    private function injectCss(string $html, ?string $cssContent, array $data): string
    {
        if (! $cssContent) {
            return $html;
        }

        $renderedCss = Blade::render($cssContent, $data);
        $styleTag = '<style>'.$renderedCss.'</style>';

        return preg_match('/<\/head>/i', $html)
            ? preg_replace('/(<\/head>)/i', $styleTag.'$1', $html, 1)
            : $styleTag.$html;
    }

    /**
     * Splits a full "<html><head><style>…</style></head><body>…</body></html>"
     * document (as produced by a template's compiled "content" Blade) into its
     * "<style>" CSS (mPDF wants that fed in separately) and its "<body>" inner HTML.
     * Falls back to treating the whole document as body-only HTML with no styles if
     * either tag is missing – a template's content isn't required to be a full
     * document.
     *
     * @return array{0: string, 1: string}
     */
    private function splitStyleAndBody(string $document): array
    {
        $style = preg_match('/<style[^>]*>(.*?)<\/style>/is', $document, $styleMatch) === 1
            ? $styleMatch[1]
            : '';

        $body = preg_match('/<body[^>]*>(.*)<\/body>/is', $document, $bodyMatch) === 1
            ? $bodyMatch[1]
            : $document;

        return [$style, $body];
    }

    /**
     * Reads the "@page { margin: <top> <right> <bottom> <left>; }" rule a template's
     * own <style> declares (all four values in "<number>mm" shorthand order, exactly
     * what every shipped template uses) and returns it as mPDF's four separate margin_*
     * constructor options. Falls back to mPDF's own defaults (16mm) for any side the
     * rule doesn't specify or when there's no @page rule at all.
     *
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private function extractPageMargin(string $styleCss): array
    {
        $defaults = ['top' => 16.0, 'right' => 16.0, 'bottom' => 16.0, 'left' => 16.0];

        if (preg_match('/@page\s*\{[^}]*margin\s*:\s*([\d.]+)mm\s+([\d.]+)mm\s+([\d.]+)mm\s+([\d.]+)mm/i', $styleCss, $m) !== 1) {
            return $defaults;
        }

        return [
            'top' => (float) $m[1],
            'right' => (float) $m[2],
            'bottom' => (float) $m[3],
            'left' => (float) $m[4],
        ];
    }

    /**
     * "position: fixed" is what makes the header/footer repeat on every page of the
     * PDF export, placed within the page margins DomPDF reserves for it – but in a
     * plain HTML preview (a browser, with no concept of "pages"/margins), the exact
     * same fixed positioning just overlays the header/footer on top of the content
     * instead, since nothing otherwise reserves space for it there. This script
     * measures the header/footer's actual rendered height and pads the body to match,
     * so the content starts below/above them in a browser – and has no effect at all
     * on the PDF export (which no longer uses this overlay at all, see renderPdf()),
     * since DomPDF/mPDF do not execute JavaScript by default.
     */
    private function reserveSpaceScript(): string
    {
        return <<<'HTML'
            <script>
                (function () {
                    function reserveSpace() {
                        var header = document.getElementById('__billing-template-header');
                        var footer = document.getElementById('__billing-template-footer');
                        if (header) {
                            document.body.style.paddingTop = header.offsetHeight + 'px';
                            document.body.style.setProperty('--hv-header-height', header.offsetHeight + 'px');
                        }
                        if (footer) { document.body.style.paddingBottom = footer.offsetHeight + 'px'; }
                    }
                    reserveSpace();
                    window.addEventListener('load', reserveSpace);
                })();
            </script>
            HTML;
    }
}
