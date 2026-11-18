<?php

namespace Database\Seeders;

use App\Models\BillingTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the default DIN 5008 Blade billing template used to render Betriebskostenabrechnungen.
 */
class BillingTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        BillingTemplate::firstOrCreate(
            ['name' => 'Standard (DIN 5008)'],
            ['is_default' => true, 'content' => $this->defaultContent(), 'css_content' => $this->defaultCss()]
        );
    }

    /**
     * Konzept.md "### Template": "Der Content soll von der Formatierung getrennt
     * sein." – die Formatierung des Standard-Templates lebt seither hier statt in
     * einem <style>-Block innerhalb von defaultContent().
     */
    private function defaultCss(): string
    {
        return <<<'CSS'
        /* top-Margin 50mm: deckt nur die Kopfzeile ab (Bild + zweizeiliger Text, real
           gemessen ca. 45mm hoch, siehe .header-box), NICHT zusätzlich das DIN-5008-
           Anschriftfenster (siehe .address-window unten) – das braucht keinen eigenen
           Platz im margin_top, weil "position: absolute" es unabhängig von den
           Seiten-Margins direkt relativ zur physischen Blattecke positioniert. Dadurch
           beginnt der Fließtext auf Folgeseiten (ohne Anschriftfenster) direkt unter der
           Kopfzeile statt mit ungenutztem Leerraum. Auf der ersten Seite jeder
           Abrechnung beginnt der Text per @@contentStart('75mm') (im Content) unter dem
           Anschriftfenster – unabhängig von der Höhe der Kopfzeile. */
        @page { margin: 50mm 20mm 20mm 25mm; }
        body { font-family: "Helvetica", sans-serif; font-size: 10pt; color: #111; }
        /* DIN 5008 Fensterumschlag (Langformat): das Sichtfenster beginnt 20mm von
           links, 27mm von oben und ist ca. 85mm x 45mm groß (endet bei 72mm von oben).
           Absolut statt im Textfluss positioniert, damit die Anschrift unabhängig von
           einer variabel hohen Kopfzeile immer exakt im Fenster landet. */
        .address-window { position: absolute; top: 27mm; left: 20mm; width: 85mm; height: 45mm; }
        .sender-line { font-size: 7pt; text-decoration: underline; margin-bottom: 2mm; }
        .address-block { margin-top: 8mm; }
        .info-block { text-align: right; margin-bottom: 6mm; }
        .subject { font-weight: bold; font-size: 12pt; margin-bottom: 5mm; }
        h3 { font-size: 10pt; margin: 5mm 0 1mm; page-break-after: avoid; }
        table.info-table { width: 100%; border-collapse: collapse; margin: 3mm 0 5mm; }
        table.info-table td { padding: 0.5mm 2mm 0.5mm 0; vertical-align: top; }
        table.info-table td.label { font-weight: bold; width: 55mm; }
        table.month-table { width: 100%; border-collapse: collapse; margin: 1mm 0 5mm; }
        table.month-table td { padding: 0.5mm 2mm; }
        table.month-table td.month { width: 22mm; }
        table.month-table td.amount { width: 22mm; text-align: right; }
        table.month-table tr.sum td { border-top: 1px solid #111; font-weight: bold; padding-top: 1mm; }
        table.bka-table { width: 100%; border-collapse: collapse; margin: 1mm 0 5mm; }
        table.bka-table th, table.bka-table td { border-bottom: 1px solid #ccc; padding: 1mm 2mm; text-align: left; }
        table.bka-table td.amount, table.bka-table th.amount { text-align: right; }
        table.bka-table tr.sum td { border-top: 2px solid #111; border-bottom: none; font-weight: bold; }
        table.summary-table { width: 100%; border-collapse: collapse; margin: 5mm 0; }
        table.summary-table td { padding: 1mm 2mm; }
        table.summary-table td.amount { text-align: right; width: 30mm; }
        table.summary-table tr.total td { border-top: 1px solid #111; font-weight: bold; }
        table.summary-table tr.balance td { border-top: 2px solid #111; font-weight: bold; }
        CSS;
    }

    private function defaultContent(): string
    {
        return <<<'BLADE'
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
</head>
<body>
    @forelse ($leases as $entry)
        @if (! $loop->first)
            <pagebreak />
        @endif
        @php
                $primaryTenant = $entry['tenants']->first();
                $isVacancy = $entry['isVacancy'] ?? false;
            @endphp
            @if ($isVacancy)
                <div class="sender-line">
                    {{ $managementGroup->description ?? 'Hausverwaltung' }} &middot; {{ $managementUnit->street }}, {{ $managementUnit->postal_code }} {{ $managementUnit->city }}
                </div>
                {{-- Konzept.md "##### Leerstände": kein Anschreiben an einen Mieter (es
                     gibt keinen), sondern ein interner Beleg für Vermieter/Steuerübersicht. --}}
                <div class="subject">
                    Leerstand {{ $entry['rentalUnit']->label() }} &ndash; Betriebskostenabrechnung {{ $billingInterval->start_date->format('d.m.Y') }} &ndash; {{ $billingInterval->end_date->format('d.m.Y') }}
                </div>
                <p>Interner Beleg: Vermieteranteil für den Leerstandszeitraum dieser Mieteinheit.</p>
            @else
                {{-- DIN 5008 Fensterumschlag: Absenderzeile + Anschrift zusammen im
                     Sichtfenster, absolut positioniert (siehe .address-window). --}}
                <div class="address-window">
                    <div class="sender-line">
                        {{ $managementGroup->description ?? 'Hausverwaltung' }} &middot; {{ $managementUnit->street }}, {{ $managementUnit->postal_code }} {{ $managementUnit->city }}
                    </div>
                    <div class="address-block">
                        @if ($primaryTenant?->salutation){{ $primaryTenant->salutation }}<br>@endif
                        {{ $entry['tenantNames'] }}<br>
                        {{ $primaryTenant?->street }}<br>
                        {{ $primaryTenant?->postal_code }} {{ $primaryTenant?->city }}
                    </div>
                </div>

                {{-- Text der ersten Seite beginnt 75mm unter der Blattkante: unter dem
                     bei 72mm endenden Anschriftfenster (+3mm Abstand). --}}
                @contentStart('75mm')
                <div class="info-block">
                    {{ now()->format('d.m.Y') }}
                </div>

                <div class="subject">
                    Betriebskostenabrechnung {{ $billingInterval->start_date->format('d.m.Y') }} &ndash; {{ $billingInterval->end_date->format('d.m.Y') }}
                </div>

                <p>
                    Sehr geehrte(r) {{ $entry['tenantNames'] }},<br><br>
                    hiermit erhalten Sie die Betriebskostenabrechnung für das o. g. Jahr.
                </p>
            @endif

            <table class="info-table">
                <tr><td class="label">Verwaltungsgruppe:</td><td>{{ $managementGroup->description }}</td></tr>
                <tr><td class="label">Objekt:</td><td>{{ $managementUnit->street }}, {{ $managementUnit->postal_code }} {{ $managementUnit->city }}</td></tr>
                <tr><td class="label">Einheit:</td><td>{{ $entry['rentalUnit']->label() }}</td></tr>
                @if (abs($entry['allocatedCosts']['byStage']['managementGroup']['total']) > 0.001)
                    <tr><td class="label">Wohneinheiten (Verwaltungsgruppe):</td><td>{{ $managementGroupRelevantUnits }}</td></tr>
                @endif
                <tr><td class="label">Wohneinheiten:</td><td>{{ $managementUnitRelevantUnits }}</td></tr>
                @if (abs($entry['allocatedCosts']['byStage']['managementGroup']['total']) > 0.001)
                    <tr><td class="label">Wohnfläche (Verwaltungsgruppe):</td><td>{{ $managementGroupTotalArea }} m&sup2;</td></tr>
                @endif
                <tr><td class="label">Wohnfläche:</td><td>{{ $managementUnitTotalArea }} m&sup2;</td></tr>
                <tr><td class="label">Wohnfläche (Mietvertrag):</td><td>{{ $entry['rentalUnit']->size ?? '-' }} m&sup2;</td></tr>
                <tr><td class="label">Abrechnungszeitraum:</td><td>{{ $billingInterval->start_date->format('d.m.Y') }} bis {{ $billingInterval->end_date->format('d.m.Y') }} = {{ $billingIntervalDays }} Tage</td></tr>
                <tr><td class="label">{{ $isVacancy ? 'Leerstandszeitraum' : 'Mietzeitraum' }}:</td><td>{{ $entry['periodStart']->format('d.m.Y') }} bis {{ $entry['periodEnd']->format('d.m.Y') }} = {{ $entry['days'] }} Tage</td></tr>
            </table>

            @if ($entry['monthlyUtilityAdvances']->isNotEmpty())
                <h3>Vorauszahlungen</h3>
                <table class="month-table">
                    @foreach ($entry['monthlyUtilityAdvances']->chunk(3) as $monthRow)
                        <tr>
                            @foreach ($monthRow as $month)
                                <td class="month">{{ $month['label'] }}</td>
                                <td class="amount">{{ euro($month['amount']) }} &euro;</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="sum">
                        <td colspan="6">Summe Vorauszahlungen: {{ euro($entry['totalUtilityAdvance']) }} &euro;</td>
                    </tr>
                </table>
            @endif

            @if (abs($entry['allocatedCosts']['byStage']['managementGroup']['total']) > 0.001)
                <h3>Verwaltungsgruppe</h3>
                <table class="bka-table">
                    <thead>
                        <tr><th>Kategorie</th><th class="amount">Summe</th><th>Schlüssel</th><th class="amount">Anteil</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($entry['allocatedCosts']['byStage']['managementGroup']['categoryDetails'] as $kategorie => $detail)
                            <tr>
                                <td>{{ $kategorie }}</td>
                                <td class="amount">{{ euro($detail['sum']) }} &euro;</td>
                                <td>{{ $detail['key'] }}</td>
                                <td class="amount">{{ euro($detail['share']) }} &euro;</td>
                            </tr>
                        @endforeach
                        <tr class="sum">
                            <td colspan="3">Summe Verwaltungsgruppe</td>
                            <td class="amount">{{ euro($entry['allocatedCosts']['byStage']['managementGroup']['total']) }} &euro;</td>
                        </tr>
                    </tbody>
                </table>
            @endif

            @if (abs($entry['allocatedCosts']['byStage']['managementUnit']['total']) > 0.001)
                <h3>Objekt</h3>
                <table class="bka-table">
                    <thead>
                        <tr><th>Kategorie</th><th class="amount">Summe</th><th>Schlüssel</th><th class="amount">Anteil</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($entry['allocatedCosts']['byStage']['managementUnit']['categoryDetails'] as $kategorie => $detail)
                            <tr>
                                <td>{{ $kategorie }}</td>
                                <td class="amount">{{ euro($detail['sum']) }} &euro;</td>
                                <td>{{ $detail['key'] }}</td>
                                <td class="amount">{{ euro($detail['share']) }} &euro;</td>
                            </tr>
                        @endforeach
                        <tr class="sum">
                            <td colspan="3">Summe Verwaltungseinheit</td>
                            <td class="amount">{{ euro($entry['allocatedCosts']['byStage']['managementUnit']['total']) }} &euro;</td>
                        </tr>
                    </tbody>
                </table>
            @endif

            @if (abs($entry['allocatedCosts']['byStage']['rentalUnit']['total']) > 0.001)
                <h3>Mieteinheit</h3>
                <table class="bka-table">
                    <thead>
                        <tr><th>Kategorie</th><th class="amount">Summe</th><th>Schlüssel</th><th class="amount">Anteil</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($entry['allocatedCosts']['byStage']['rentalUnit']['categoryDetails'] as $kategorie => $detail)
                            <tr>
                                <td>{{ $kategorie }}</td>
                                <td class="amount">{{ euro($detail['sum']) }} &euro;</td>
                                <td>{{ $detail['key'] }}</td>
                                <td class="amount">{{ euro($detail['share']) }} &euro;</td>
                            </tr>
                        @endforeach
                        <tr class="sum">
                            <td colspan="3">Summe Mieteinheit</td>
                            <td class="amount">{{ euro($entry['allocatedCosts']['byStage']['rentalUnit']['total']) }} &euro;</td>
                        </tr>
                    </tbody>
                </table>
            @endif

            @if (abs($entry['allocatedCosts']['byStage']['lease']['total']) > 0.001)
                <h3>Individuell</h3>
                <table class="bka-table">
                    <thead>
                        <tr><th>Kategorie</th><th class="amount">Betrag</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($entry['allocatedCosts']['byStage']['lease']['byCategory'] as $kategorie => $betrag)
                            <tr>
                                <td>{{ $kategorie }}</td>
                                <td class="amount">{{ euro($betrag) }} &euro;</td>
                            </tr>
                        @endforeach
                        <tr class="sum">
                            <td>Summe Individuell</td>
                            <td class="amount">{{ euro($entry['allocatedCosts']['byStage']['lease']['total']) }} &euro;</td>
                        </tr>
                    </tbody>
                </table>
            @endif

            @php
                $vacancyClaimsTotal = $isVacancy ? (float) $entry['claims']->sum('amount') : 0.0;
                $displayBalance = $entry['balance'] + $vacancyClaimsTotal;
            @endphp
            <table class="summary-table">
                @if (abs($entry['allocatedCosts']['byStage']['managementGroup']['total']) > 0.001)
                    <tr><td>Anteil Verwaltungsgruppe</td><td class="amount">{{ euro($entry['allocatedCosts']['byStage']['managementGroup']['total']) }} &euro;</td></tr>
                @endif
            
                @if (abs($entry['allocatedCosts']['byStage']['managementUnit']['total']) > 0.001)
                    <tr><td>Anteil Objekt</td><td class="amount">{{ euro($entry['allocatedCosts']['byStage']['managementUnit']['total']) }} &euro;</td></tr>
                @endif

                @if (abs($entry['allocatedCosts']['byStage']['rentalUnit']['total']) > 0.001)
                    <tr><td>Anteil Mieteinheit</td><td class="amount">{{ euro($entry['allocatedCosts']['byStage']['rentalUnit']['total']) }} &euro;</td></tr>
                @endif

                @if (abs($entry['allocatedCosts']['byStage']['lease']['total']) > 0.001)
                    <tr><td>Individueller Anteil</td><td class="amount">{{ euro($entry['allocatedCosts']['byStage']['lease']['total']) }} &euro;</td></tr>
                @endif
                <tr class="total">
                    <td>Summe Betriebskosten</td>
                    <td class="amount">{{ euro($entry['allocatedCosts']['total']) }} &euro;</td>
                </tr>
                <tr>
                    <td>- Vorauszahlungen</td>
                    <td class="amount">{{ euro($entry['totalUtilityAdvance']) }} &euro;</td>
                </tr>
                @if ($vacancyClaimsTotal != 0)
                    <tr>
                        <td>+ Forderungen</td>
                        <td class="amount">{{ euro($vacancyClaimsTotal) }} &euro;</td>
                    </tr>
                @endif
                <tr class="balance">
                    <td>
                        @if ($isVacancy)
                            Vom Vermieter zu tragen
                        @elseif ($entry['balance'] > 0)
                            Nachzahlung
                        @elseif ($entry['balance'] < 0)
                            Rückerstattung
                        @else
                            Ausgleich
                        @endif
                    </td>
                    <td class="amount">{{ euro(abs($displayBalance)) }} &euro;</td>
                </tr>
            </table>

            @if ($isVacancy && $entry['claims']->isNotEmpty())
                <h3>Forderungen</h3>
                <table class="bka-table">
                    <thead>
                        <tr><th>Datum</th><th class="amount">Betrag</th><th>Status</th><th>Notiz</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($entry['claims'] as $claim)
                            <tr>
                                <td>{{ $claim->date->format('d.m.Y') }}</td>
                                <td class="amount">{{ euro($claim->amount) }} &euro;</td>
                                <td>{{ $claim->isOpen() ? 'Offen' : 'Gebucht' }}</td>
                                <td>{{ $claim->notes }}</td>
                            </tr>
                        @endforeach
                        <tr class="sum">
                            <td colspan="3">Summe Forderungen</td>
                            <td class="amount">{{ euro($vacancyClaimsTotal) }} &euro;</td>
                        </tr>
                    </tbody>
                </table>
            @endif

            @unless ($isVacancy)
                @php
                    $otherRentsSum = 0;
                    foreach ($entry['otherRents'] as $otherRent) {
                        $otherRentsSum += $otherRent['coldRent'] + $otherRent['utilityAdvance'];
                    }
                    $newTransferAmount = $entry['currentColdRent'] + $entry['suggestedUtilityAdvance'] + $otherRentsSum;
                @endphp

                <p>
                    Neue Vorauszahlung ergibt: <strong>{{ euro($entry['suggestedUtilityAdvance']) }} &euro;</strong><br>
                    Neuer Überweisungsbetrag: <strong>{{ euro($newTransferAmount) }} &euro;</strong>
                </p>

                @if ($entry['balance'] != 0)
                    <p>
                        Fälligkeit {{ $entry['balance'] > 0 ? 'Nachzahlung' : 'Rückerstattung' }}: {{ now()->addMonth()->format('d.m.Y') }}<br>
                        Gültigkeit neue Vorauszahlung ab: {{ now()->startOfMonth()->addMonthNoOverflow()->format('d.m.Y') }}
                    </p>
                @else
                    <p>
                        Gültigkeit neue Vorauszahlung ab: {{ now()->startOfMonth()->addMonthNoOverflow()->format('d.m.Y') }}
                    </p>
                @endif

                <p>
                    Nach Terminvereinbarung haben Sie die Möglichkeit, die Originalbelege einzusehen.
                </p>

                @if ($entry['balance'] > 0)
                    <p>Bitte begleichen Sie die Nachzahlung zum o. g. Zeitpunkt.</p>
                @elseif ($entry['balance'] < 0)
                    <p>Die Rückerstattung wird Ihnen zum o. g. Zeitpunkt überwiesen.</p>
                @endif

                <p style="margin-top: 10mm;">
                    Mit freundlichen Grüßen<br>
                    <br>
                    <br>
                    <br>
                    @if ($landlord?->company_name)
                        {{ $landlord->company_name }}<br>
                    @endif
                    {{ $landlord?->fullName() ?: ($managementGroup->description ?? 'Ihre Hausverwaltung') }}
                </p>
            @endunless

            @if ($bookingsByLevel->isNotEmpty())
                <div style="page-break-before: always;">
                <h3>Anlage: Buchungsübersicht</h3>
                @foreach ($bookingsByLevel as $level => $categories)
                    <h4 style="margin: 3mm 0 1mm; font-size: 10pt;">{{ $level }}</h4>
                    @foreach ($categories as $kategorie => $buchungen)
                        <table class="bka-table">
                            <thead>
                                <tr><th colspan="4">{{ $kategorie }}</th></tr>
                                <tr><th>Datum</th><th>Empfänger</th><th>Beschreibung</th><th class="amount">Betrag</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($buchungen as $buchung)
                                    <tr>
                                        <td>{{ $buchung['date']->format('d.m.Y') }}</td>
                                        <td>{{ $buchung['issuer'] }}</td>
                                        <td>{{ $buchung['description'] }}</td>
                                        <td class="amount">{{ euro($buchung['amount']) }} &euro;</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endforeach
                @endforeach
                </div>
            @endif
    @empty
        <p>Keine Mietverträge für diesen Abrechnungszeitraum gefunden.</p>
    @endforelse
</body>
</html>
BLADE;
    }
}
