<?php

namespace App\Http\Controllers;

use App\Models\BillingInterval;
use App\Models\BillingTemplate;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Services\BillingTemplateRenderer;
use App\Services\FakeBillingStatementData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Mpdf\Tag as MpdfTag;

/**
 * CRUD for Abrechnungsvorlagen (billing statement templates), including header image
 * upload/removal and duplication of an existing template. Self-created templates are
 * assigned to one of the creating user's Benutzergruppen; the built-in/"internal"
 * template (no group) remains visible/duplicable by everyone but not directly editable.
 */
class BillingTemplateController extends Controller
{
    /** libxml-Fehlercode XML_HTML_UNKNOWN_TAG ("Tag … invalid"). */
    private const LIBXML_UNKNOWN_TAG = 801;

    private const UNKNOWN_TAG_MESSAGE = '/^Tag (\w+) invalid/';

    public function __construct()
    {
        $this->authorizeResource(BillingTemplate::class, 'billingTemplate');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $billingTemplates = BillingTemplate::visibleTo($request->user())->with('userGroup')->orderBy('name')->get();

        return view('billing-templates.index', compact('billingTemplates'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $groups = $request->user()->groups;

        return view('billing-templates.create', compact('groups'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_group_id' => ['required', 'integer', Rule::in($request->user()->groups->pluck('id'))],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'css_content' => ['nullable', 'string'],
            'header' => ['nullable', 'string'],
            'footer' => ['nullable', 'string'],
            'header_image' => ['nullable', 'image', 'max:2048'],
            'is_default' => ['boolean'],
        ]);

        // "Standard" bedeutet für ein selbst erstelltes (gruppenzugehöriges) Template
        // "Standard-Template für die Verwaltungsgruppen dieser Benutzergruppe" – nicht
        // den globalen `is_default`-Fallback, der ausschließlich dem eingebauten
        // Template ohne Gruppe vorbehalten bleibt.
        $makeDefaultForGroup = $request->boolean('is_default');
        $validated['is_default'] = false;

        if ($request->hasFile('header_image')) {
            $validated['header_image_path'] = $request->file('header_image')->store('billing-template-images', 'public');
        }
        unset($validated['header_image']);

        $billingTemplate = BillingTemplate::create($validated);

        if ($makeDefaultForGroup) {
            $this->makeDefaultForOwnManagementGroups($billingTemplate);
        }

        return redirect()->route('billing-templates.show', $billingTemplate);
    }

    /**
     * Display the specified resource.
     */
    public function show(BillingTemplate $billingTemplate): View
    {
        return view('billing-templates.show', compact('billingTemplate'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, BillingTemplate $billingTemplate): View
    {
        $groups = $request->user()->groups;

        return view('billing-templates.edit', compact('billingTemplate', 'groups'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BillingTemplate $billingTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'user_group_id' => ['required', 'integer', Rule::in($request->user()->groups->pluck('id'))],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'css_content' => ['nullable', 'string'],
            'header' => ['nullable', 'string'],
            'footer' => ['nullable', 'string'],
            'header_image' => ['nullable', 'image', 'max:2048'],
            'remove_header_image' => ['boolean'],
            'is_default' => ['boolean'],
        ]);

        $makeDefaultForGroup = $request->boolean('is_default');
        $validated['is_default'] = false;

        if ($request->hasFile('header_image')) {
            if ($billingTemplate->header_image_path) {
                Storage::disk('public')->delete($billingTemplate->header_image_path);
            }
            $validated['header_image_path'] = $request->file('header_image')->store('billing-template-images', 'public');
        } elseif ($request->boolean('remove_header_image') && $billingTemplate->header_image_path) {
            Storage::disk('public')->delete($billingTemplate->header_image_path);
            $validated['header_image_path'] = null;
        }
        unset($validated['header_image'], $validated['remove_header_image']);

        $billingTemplate->update($validated);

        if ($makeDefaultForGroup) {
            $this->makeDefaultForOwnManagementGroups($billingTemplate);
        }

        return redirect()->route('billing-templates.show', $billingTemplate);
    }

    /**
     * Duplicate the specified resource, including its header image if any. The copy is
     * assigned to one of the duplicating user's own Benutzergruppen (a duplicate is
     * itself a newly self-created template, even when duplicating the built-in one).
     */
    public function duplicate(Request $request, BillingTemplate $billingTemplate): RedirectResponse
    {
        $this->authorize('view', $billingTemplate);

        $copy = $billingTemplate->replicate(['is_default']);
        $copy->name = __(':name (Kopie)', ['name' => $billingTemplate->name]);
        $copy->is_default = false;
        $copy->user_group_id = $request->user()->groups->first()?->id;

        if ($billingTemplate->header_image_path) {
            $extension = pathinfo($billingTemplate->header_image_path, PATHINFO_EXTENSION);
            $newPath = 'billing-template-images/'.Str::uuid().($extension ? ".{$extension}" : '');
            Storage::disk('public')->copy($billingTemplate->header_image_path, $newPath);
            $copy->header_image_path = $newPath;
        }

        $copy->save();

        return redirect()->route('billing-templates.show', $copy);
    }

    /**
     * Renders the current (possibly unsaved) Header/Inhalt/Fußzeile from the edit
     * form with fake data, so a user can preview their in-progress template without
     * saving first and without needing a real Verwaltungsgruppe/-einheit/Abrechnung.
     * The template's own already-uploaded Kopf-Bild (if any) is reused as-is, since a
     * file input can't be "resubmitted" alongside a plain preview request.
     */
    public function preview(Request $request, BillingTemplate $billingTemplate, FakeBillingStatementData $fakeData, BillingTemplateRenderer $renderer): Response
    {
        $this->authorize('update', $billingTemplate);

        $validated = $request->validate([
            'header' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'footer' => ['nullable', 'string'],
            'css_content' => ['nullable', 'string'],
        ]);

        $data = $fakeData->build();
        $data['headerImage'] = $billingTemplate->headerImageDataUri();

        $html = $renderer->render($validated['content'] ?? '', $validated['header'] ?? null, $validated['footer'] ?? null, $data, $validated['css_content'] ?? null);

        return response($html);
    }

    /**
     * Checks the current (possibly unsaved) Header/Inhalt/Fußzeile from the edit form
     * for Blade-Syntaxfehler und unbekannte Variablen. Two checks run: (1) a STATIC
     * scan of the compiled PHP for any `$variable` that is neither a known top-level
     * data key nor introduced by the template itself (via `@foreach ... as $x`/
     * `@php($x = ...)`) – this is what catches a typo'd variable name even when it
     * sits inside an `@if`/loop branch that the fake test data happens not to enter, a
     * blind spot rendering-with-fake-data alone can never cover; then (2) an actual
     * render with fake data (like preview()), converting PHP warnings (e.g. "Undefined
     * array key", which is what a typo'd array key triggers) into a catchable
     * exception instead of silently ignoring them – this still catches genuine Blade
     * syntax errors and array-key typos the static scan doesn't attempt. Redirects
     * back to the edit form with either a success flash or an error message – no new
     * tab, so the user keeps their in-progress, unsaved form content (old()).
     */
    public function check(Request $request, BillingTemplate $billingTemplate, FakeBillingStatementData $fakeData, BillingTemplateRenderer $renderer): RedirectResponse
    {
        $this->authorize('update', $billingTemplate);

        $validated = $request->validate([
            'header' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'footer' => ['nullable', 'string'],
            'css_content' => ['nullable', 'string'],
        ]);

        $data = $fakeData->build();
        $data['headerImage'] = $billingTemplate->headerImageDataUri();

        try {
            foreach (['header', 'content', 'footer', 'css_content'] as $part) {
                if (! empty($validated[$part])) {
                    $php = $this->phpSegments(Blade::compileString($validated[$part]));
                    $this->assertNoUnknownVariables($php, array_keys($data));
                    $this->assertNoUnknownProperties($php);
                }
            }
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['template_check' => $e->getMessage()]);
        }

        $previousHandler = set_error_handler(
            fn ($severity, $message) => throw new \ErrorException($message, 0, $severity),
            E_WARNING | E_NOTICE
        );

        try {
            $html = $renderer->render($validated['content'] ?? '', $validated['header'] ?? null, $validated['footer'] ?? null, $data, $validated['css_content'] ?? null);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['template_check' => $this->templateCheckErrorMessage($e)]);
        } finally {
            set_error_handler($previousHandler);
        }

        if (($htmlError = $this->findHtmlError($html)) !== null) {
            return back()->withInput()->withErrors(['template_check' => $htmlError]);
        }

        return back()->withInput()->with('status', __('Template ist gültig: keine Syntaxfehler, keine unbekannten Variablen und kein fehlerhaftes HTML gefunden.'));
    }

    /**
     * Checks the rendered HTML for structural problems (e.g. a mistyped/mismatched
     * closing tag like "</htm>" instead of "</html>") via libxml. DomPDF's HTML
     * parser is considerably stricter about this than a browser is – a malformed tag
     * a browser silently repairs can still break or garble the PDF export. Bare "&"
     * characters typed directly as plain text (e.g. a company name like "Müller &
     * Sohn") are common, legitimate content and deliberately not flagged – Blade's
     * "{{ }}" already HTML-escapes actual data values, so this can only affect a "&"
     * typed directly into the template source itself.
     */
    private function findHtmlError(string $html): ?string
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML($html);
        $errors = array_values(array_filter(
            libxml_get_errors(),
            fn ($error) => ! str_contains($error->message, 'EntityRef') && ! $this->isPdfTag($error)
        ));
        libxml_clear_errors();

        if (empty($errors)) {
            return null;
        }

        return __('Fehler im HTML: :message (Zeile :line).', [
            'message' => trim($errors[0]->message),
            'line' => $errors[0]->line,
        ]);
    }

    /**
     * libxml kennt nur HTML 4 und meldet jedes andere Tag als unbekannt – auch die von
     * mPDF (z. B. <pagebreak />, siehe Hilfeseite) und HTML5-Tags wie <section>. Ein
     * solches Tag ist kein Fehler, wenn mPDF es darstellt, also eine eigene,
     * nicht-abstrakte Tag-Klasse dafür hat. Ein Tippfehler (<pagebrek>) bleibt ein Fehler.
     */
    private function isPdfTag(\LibXMLError $error): bool
    {
        if ($error->code !== self::LIBXML_UNKNOWN_TAG || preg_match(self::UNKNOWN_TAG_MESSAGE, $error->message, $match) !== 1) {
            return false;
        }

        $class = MpdfTag::getTagClassName(strtoupper($match[1]));

        return class_exists($class)
            && is_subclass_of($class, MpdfTag\Tag::class)
            && ! (new \ReflectionClass($class))->isAbstract();
    }

    /**
     * Statically scans an already-compiled Blade fragment's PHP for a `$variable`
     * usage that is neither a known top-level data key nor introduced by the template
     * itself, and throws with a hint naming it if one is found.
     *
     * @param  array<int, string>  $knownKeys
     */
    private function assertNoUnknownVariables(string $php, array $knownKeys): void
    {
        $declared = array_fill_keys($knownKeys, true)
            + array_fill_keys(['__env', '__data', '__path', 'loop', 'this', '__currentLoopData'], true)
            + array_fill_keys($this->declaredVariableNames($php), true);

        if (preg_match_all('/\$([a-zA-Z_]\w*)/', $php, $matches) === 0) {
            return;
        }

        foreach ($matches[1] as $name) {
            if (! isset($declared[$name]) && ! str_starts_with($name, '__')) {
                throw new \RuntimeException(__(
                    'Unbekannte Variable $:name. Bitte auf der Hilfeseite prüfen, welche Variablen zur Verfügung stehen.',
                    ['name' => $name]
                ));
            }
        }
    }

    /**
     * Maps a template's top-level model variables (managementGroup/managementUnit/…) to
     * the Eloquent model whose actual DB columns define its "known attributes" – see
     * knownProperties(), which reads them straight from the database schema instead of
     * a manually-maintained list. A manual list drifts silently: e.g. Lease's
     * `is_pre_contract` column was added after this list and never caught up, so a
     * template could reach `$lease->is_pre_contract` without the "Template überprüfen"
     * check ever flagging a genuine typo of that same property. Deliberately NOT
     * including relations or methods, since those would need to be validated against
     * an unbounded API surface (Carbon, Eloquent Collections, …) and risk false
     * positives; only a plain `->property` access (not `->method(...)`) is checked.
     */
    private const MODEL_CLASSES = [
        'managementGroup' => ManagementGroup::class,
        'managementUnit' => ManagementUnit::class,
        'billingInterval' => BillingInterval::class,
        'lease' => Lease::class,
        'rentalUnit' => RentalUnit::class,
        'tenant' => Tenant::class,
        'landlord' => Landlord::class,
    ];

    /**
     * @return array<int, string>
     */
    private function knownProperties(string $model): array
    {
        static $columnsByTable = [];

        /** @var class-string<Model> $modelClass */
        $modelClass = self::MODEL_CLASSES[$model];
        $table = (new $modelClass)->getTable();

        return $columnsByTable[$table] ??= Schema::getColumnListing($table);
    }

    /**
     * Statically scans an already-compiled Blade fragment's PHP for a `->property`
     * access on one of the top-level model variables ($managementGroup/
     * $managementUnit/$billingInterval) or a well-known array-key access
     * (`['lease']`/`['rentalUnit']`/`['tenants']->first()`), and throws if the
     * property isn't one of that model's known attributes – this is what catches a
     * typo like `$managementGroup->descriptio` (missing the "n"), which Eloquent
     * itself would otherwise silently resolve to `null` with no warning at all
     * (unlike a genuinely undefined PHP variable). Deliberately limited to these
     * fixed access points and to direct attributes only (see MODEL_PROPERTIES) – it
     * does not attempt to type-check a tenant reached via `@foreach (... as $x)`,
     * relations, or method calls.
     */
    private function assertNoUnknownProperties(string $php): void
    {
        $this->assertKnownPropertyAccess($php, '\$managementGroup->', 'managementGroup');
        $this->assertKnownPropertyAccess($php, '\$managementUnit->', 'managementUnit');
        $this->assertKnownPropertyAccess($php, '\$billingInterval->', 'billingInterval');
        // $landlord is nullable, so a template usually reaches it via the null-safe "?->".
        $this->assertKnownPropertyAccess($php, '\$landlord\??->', 'landlord');
        $this->assertKnownPropertyAccess($php, preg_quote("['lease']", '/').'->', 'lease');
        $this->assertKnownPropertyAccess($php, preg_quote("['rentalUnit']", '/').'->', 'rentalUnit');
        $this->assertKnownPropertyAccess($php, preg_quote("['tenants']", '/').'->first\(\)->', 'tenant');
    }

    private function assertKnownPropertyAccess(string $php, string $accessPattern, string $model): void
    {
        // Possessives Quantifier "\w++" statt "\w+": ohne das würde PCRE bei einem
        // Methodenaufruf wie "->fullName()" den negativen Lookahead "(?!\s*\()" durch
        // Backtracking umgehen – "fullName" scheitert daran (direkt gefolgt von "("),
        // also probiert die Engine die kürzere Teilfolge "fullNam", die NICHT von "("
        // gefolgt wird (sondern vom "e"), und meldet sie fälschlich als Eigenschaft.
        if (preg_match_all('/'.$accessPattern.'(\w++)(?!\s*\()/', $php, $matches) === 0) {
            return;
        }

        foreach ($matches[1] as $property) {
            if (! in_array($property, $this->knownProperties($model), true)) {
                throw new \RuntimeException(__(
                    'Unbekannte Eigenschaft ":property" (Modell ":model"). Bitte auf der Hilfeseite prüfen, welche Eigenschaften zur Verfügung stehen.',
                    ['property' => $property, 'model' => $model]
                ));
            }
        }
    }

    /**
     * Extracts only the PHP code segments from Blade-compiled output, discarding the
     * literal HTML/text portions in between (so those can't trigger a false positive
     * "unknown variable" for an unrelated "$" in ordinary text).
     */
    private function phpSegments(string $compiled): string
    {
        preg_match_all('/<\?php(.*?)\?>/s', $compiled, $matches);

        return implode("\n", $matches[1]);
    }

    /**
     * Variable names the template itself introduces: `@foreach ($x as $entry)` /
     * `@foreach ($x as $key => $entry)` loop captures (compiled to a plain `foreach`),
     * and `@php($var = ...)`/`@php ... @endphp` assignments (including compound
     * assignment operators like `+=`, as used for a running total across a loop).
     *
     * @return array<int, string>
     */
    private function declaredVariableNames(string $php): array
    {
        preg_match_all('/foreach\s*\([^)]*\bas\s+\$(\w+)(?:\s*=>\s*\$(\w+))?\s*\)/', $php, $foreachMatches);
        preg_match_all('/\$(\w+)\s*(?:\+=|-=|\.=|\*=|\/=|\?\?=|=)(?!=)/', $php, $assignmentMatches);

        return array_values(array_filter(array_merge(
            $foreachMatches[1],
            $foreachMatches[2],
            $assignmentMatches[1]
        )));
    }

    /**
     * Turns a render-time exception into a hint a non-technical template author can
     * act on – naming the exact unknown array key where possible, instead of just
     * surfacing the raw (often PHP-internal) exception message. An "Undefined
     * variable" case can no longer reach here at all – assertNoUnknownVariables()
     * above already catches every such case statically before this dynamic render
     * ever runs.
     */
    private function templateCheckErrorMessage(\Throwable $e): string
    {
        if (preg_match('/Undefined array key "?([^"\']+)"?/', $e->getMessage(), $matches) === 1) {
            return __('Unbekannter Schlüssel ":key" in einem Array-Zugriff. Bitte auf der Hilfeseite prüfen, welche Schlüssel zur Verfügung stehen.', ['key' => $matches[1]]);
        }

        return __('Fehler im Template: :message', ['message' => $e->getMessage()]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BillingTemplate $billingTemplate): RedirectResponse
    {
        if ($billingTemplate->header_image_path) {
            Storage::disk('public')->delete($billingTemplate->header_image_path);
        }

        $billingTemplate->delete();

        return redirect()->route('billing-templates.index');
    }

    /**
     * Sets this template as the default_billing_template_id of every ManagementGroup
     * owned by the template's own Benutzergruppe (store()/update() always require a
     * user_group_id, so this is never called for the group-less "internal" template).
     */
    private function makeDefaultForOwnManagementGroups(BillingTemplate $billingTemplate): void
    {
        ManagementGroup::where('user_group_id', $billingTemplate->user_group_id)
            ->update(['default_billing_template_id' => $billingTemplate->id]);
    }
}
