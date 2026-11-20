<?php

namespace App\Http\Controllers;

use App\Services\FakeBillingStatementData;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\View;

/**
 * Explains the Blade syntax and variables available in a Betriebskostenabrechnung
 * template (see BillingStatementController::render()), so a user is able to write
 * one without needing a real Verwaltungsgruppe/-einheit/Abrechnung to test against.
 */
class TemplateHelpController extends Controller
{
    /**
     * The example content shown on the help page, demonstrating property access,
     *
     * @if, and @foreach (both over a plain list and over an associative array) -
     * also used as-is by preview() so the explanation and the live example match.
     */
    public const EXAMPLE_CONTENT = <<<'BLADE'
        <h1>Betriebskostenabrechnung</h1>
        <p>{{ $managementGroup->description }}</p>
        <p>{{ $managementUnit->street }}, {{ $managementUnit->postal_code }} {{ $managementUnit->city }}</p>
        <p>Abrechnungszeitraum: {{ $billingInterval->start_date->format('d.m.Y') }} &ndash; {{ $billingInterval->end_date->format('d.m.Y') }}</p>

        @if ($headerImage)
            <img src="{{ $headerImage }}" style="height: 20mm;">
        @endif

        @foreach ($leases as $eintrag)
            <h2>{{ $eintrag['tenantNames'] }}</h2>
            <p>{{ $eintrag['rentalUnit']->description }} ({{ $eintrag['rentalUnit']->size }} m&sup2;)</p>
            <p>Mietdauer im Abrechnungszeitraum: {{ $eintrag['days'] }} Tage</p>
        @endforeach

        <table>
            <tr><th>Kategorie</th><th>Betrag</th></tr>
            @foreach ($costsByCategory as $kategorie => $betrag)
                <tr><td>{{ $kategorie }}</td><td>{{ euro($betrag) }} &euro;</td></tr>
            @endforeach
        </table>

        <table>
            <tr><th>Monat</th><th>Betrag</th></tr>
            @foreach ($costsByMonth as $eintrag)
                <tr><td>{{ $eintrag['label'] }}</td><td>{{ euro($eintrag['amount']) }} &euro;</td></tr>
            @endforeach
        </table>
        BLADE;

    /**
     * Shows the Blade-Syntax/variables help page.
     */
    public function show(): View
    {
        return view('billing-templates.help', ['exampleContent' => self::EXAMPLE_CONTENT]);
    }

    /**
     * Renders the example content above with fake (in-memory, never persisted) data
     * matching the exact shape BillingStatementController::render() passes to a real
     * template, so a user can see what their own template's syntax would produce
     * without first needing a real Verwaltungsgruppe/-einheit/Abrechnung with data.
     */
    public function preview(FakeBillingStatementData $fakeData): Response
    {
        return response(Blade::render(self::EXAMPLE_CONTENT, $fakeData->build()));
    }
}
