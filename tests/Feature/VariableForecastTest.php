<?php

namespace Tests\Feature;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the variable-Rechnung forecasting (ForecastRefresher, ManagementGroup's
 * variable_cost_forecast_mode), triggered via the "Serie vervollständigen" action
 * (InvoiceController::completeSeries()), Kostenzeilen edits and mode changes.
 */
class VariableForecastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Invoice, 2: CostCategory, 3: ManagementGroup}
     */
    private function makeMonthlyInvoice(string $forecastMode, bool $disableAmountCap = false): array
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create([
            'description' => 'MG',
            'user_group_id' => $group->id,
            'variable_cost_forecast_mode' => $forecastMode,
        ]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $issuer = InvoiceIssuer::create(['name' => 'Kreditgeber', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::where('name', 'Tilgung')->first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-01-01',
            'booking_date' => '2026-01-01',
            'amount' => 100,
            'periodicity' => 'monthly',
            'disable_amount_cap' => $disableAmountCap,
        ]);

        return [$user, $invoice, $category, $managementGroup];
    }

    /**
     * Records a real, already-booked Kostenzeile (mirrors what a user does via
     * "Kostenzeile hinzufügen" once the real bank statement arrives), unlike the plain
     * open forecast rows completeSeries() itself generates.
     */
    private function bookCost(Invoice $invoice, CostCategory $category, string $periodDate, float $amount, ?string $bookingDate = null): Cost
    {
        return $invoice->costs()->create([
            'costable_type' => $invoice->costable->getMorphClass(),
            'costable_id' => $invoice->costable->id,
            'billing_interval_id' => $invoice->costable->managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => $category->id,
            'amount' => $amount,
            'period_date' => $periodDate,
            'booking_date' => $bookingDate ?? $periodDate,
        ]);
    }

    /**
     * Mirrors confirming an *existing* open forecast row via the "Kostenzeile
     * bearbeiten" inline form (booking_date/amount fields) – unlike bookCost(), which
     * adds a brand-new row.
     */
    private function confirmCost(Invoice $invoice, string $periodDate, float $amount, ?string $bookingDate = null): void
    {
        Cost::where('invoice_id', $invoice->id)
            ->whereDate('period_date', $periodDate)
            ->update(['amount' => $amount, 'booking_date' => $bookingDate ?? $periodDate]);
    }

    private function completeSeries(User $user, Invoice $invoice, CostCategory $category, string $until): void
    {
        $this->actingAs($user)->post(route('invoices.complete-series', $invoice), [
            'cost_category_id' => $category->id,
            'until' => $until,
        ])->assertRedirect(route('invoices.show', $invoice));
    }

    public function test_a_fixed_invoice_is_unaffected_by_the_forecast_mode(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_NONE);

        $this->bookCost($invoice, $category, '2026-01-01', 100.0);

        $this->completeSeries($user, $invoice, $category, '2026-04-01');

        // Not variable (only ever booked at the nominal amount, disable_amount_cap is
        // false): every occurrence still gets generated, at the nominal amount, even
        // though the ManagementGroup's mode is "keine Prognostizierung".
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertCount(4, $costs);
        $this->assertTrue($costs->every(fn (Cost $c) => (float) $c->amount === 100.0));

        Carbon::setTestNow();
    }

    public function test_disable_amount_cap_alone_marks_an_invoice_variable_even_with_no_booking_history_yet(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_NONE, disableAmountCap: true);

        // No Kostenzeile booked at all yet – completeSeries() has nothing to fill in
        // from January, but "none" mode still generates zero rows for Feb-Apr since
        // Betragsüberprüfung is disabled.
        $this->completeSeries($user, $invoice, $category, '2026-04-01');

        $this->assertSame(0, Cost::where('invoice_id', $invoice->id)->count());

        Carbon::setTestNow();
    }

    public function test_variable_forecast_none_creates_no_rows_for_unbooked_future_occurrences(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_NONE, disableAmountCap: true);

        $this->bookCost($invoice, $category, '2026-01-01', 136.51);
        $this->bookCost($invoice, $category, '2026-02-01', 136.90);

        $this->completeSeries($user, $invoice, $category, '2026-04-01');

        // Only the two real bookings exist; März/April were never confirmed and "keine
        // Prognostizierung" means no placeholder row was created for either.
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertCount(2, $costs);
        $this->assertSame(['2026-01-01', '2026-02-01'], $costs->map(fn (Cost $c) => $c->period_date->toDateString())->all());

        Carbon::setTestNow();
    }

    public function test_variable_forecast_last_booking_carries_the_latest_booked_amount_forward(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING, disableAmountCap: true);

        $this->bookCost($invoice, $category, '2026-01-01', 136.51);
        $this->bookCost($invoice, $category, '2026-02-01', 136.90);

        $this->completeSeries($user, $invoice, $category, '2026-04-01');

        // Schwankende Rechnung ohne Ende: die Prognose reicht bis Ende des Folgejahres.
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertCount(24, $costs);

        $mar = $costs->first(fn (Cost $c) => $c->period_date->toDateString() === '2026-03-01');
        $apr = $costs->first(fn (Cost $c) => $c->period_date->toDateString() === '2026-04-01');
        $this->assertTrue($mar->isOpen());
        $this->assertSame(136.90, (float) $mar->amount);
        $this->assertTrue($apr->isOpen());
        $this->assertSame(136.90, (float) $apr->amount);

        Carbon::setTestNow();
    }

    /**
     * Condition (b) from the Nutzeranfrage: even with Betragsüberprüfung still enabled,
     * an invoice whose already-booked occurrences of the same category booked genuinely
     * different amounts (rather than an explicit disable_amount_cap flag) is treated as
     * variable too.
     */
    public function test_two_differing_booked_amounts_alone_mark_an_invoice_variable(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING, disableAmountCap: false);

        $this->bookCost($invoice, $category, '2026-01-01', 100.0);
        $this->bookCost($invoice, $category, '2026-02-01', 105.0);

        $this->completeSeries($user, $invoice, $category, '2026-03-01');

        $mar = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-03-01')->first();
        $this->assertNotNull($mar);
        $this->assertSame(105.0, (float) $mar->amount);
    }

    public function test_variable_forecast_regression_extrapolates_the_linear_trend(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_REGRESSION, disableAmountCap: true);

        // Tilgung steigt hier um exakt 0,50 € pro Monat (Jan 100, Feb 100.5, Mär 101) –
        // eine Ausgleichsgerade sagt für April 101,50 vorher, nicht die eingefrorenen
        // 101 aus März.
        $this->bookCost($invoice, $category, '2026-01-01', 100.00);
        $this->bookCost($invoice, $category, '2026-02-01', 100.50);
        $this->bookCost($invoice, $category, '2026-03-01', 101.00);

        $this->completeSeries($user, $invoice, $category, '2026-05-01');

        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $apr = $costs->first(fn (Cost $c) => $c->period_date->toDateString() === '2026-04-01');
        $may = $costs->first(fn (Cost $c) => $c->period_date->toDateString() === '2026-05-01');
        $this->assertSame(101.50, (float) $apr->amount);
        $this->assertSame(102.00, (float) $may->amount);

        Carbon::setTestNow();
    }

    public function test_variable_forecast_regression_falls_back_to_last_booking_with_fewer_than_two_points(): void
    {
        Carbon::setTestNow('2026-03-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_REGRESSION, disableAmountCap: true);

        $this->bookCost($invoice, $category, '2026-01-01', 136.51);

        $this->completeSeries($user, $invoice, $category, '2026-03-01');

        $feb = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-02-01')->first();
        $this->assertSame(136.51, (float) $feb->amount);

        Carbon::setTestNow();
    }

    /**
     * Nutzeranfrage: "Diese Entscheidung muss zur Laufzeit für jede einzelne Rechnung
     * erfolgen." – bestFittingCurve() doesn't always pick the linear Ausgleichsgerade;
     * for a Rechnung whose booked amounts genuinely double every period (a perfect
     * exponential curve, R² = 1.0), the exponential candidate is chosen instead. Uses
     * four booked occurrences rather than three: with exactly three points a quadratic
     * (3 free parameters) also happens to fit any curve perfectly, tying with – and,
     * by bestFittingCurve()'s simplicity-favoring tie-break, beating – the genuinely
     * better exponential candidate; a fourth point makes the quadratic an honest
     * (imperfect) least-squares fit again, so only the true exponential trend still
     * scores a perfect R².
     */
    public function test_variable_forecast_regression_picks_the_exponential_curve_when_it_fits_best(): void
    {
        Carbon::setTestNow('2026-05-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_REGRESSION, disableAmountCap: true);

        $this->bookCost($invoice, $category, '2026-01-01', 100.0);
        $this->bookCost($invoice, $category, '2026-02-01', 200.0);
        $this->bookCost($invoice, $category, '2026-03-01', 400.0);
        $this->bookCost($invoice, $category, '2026-04-01', 800.0);

        $this->completeSeries($user, $invoice, $category, '2026-05-01');

        $may = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-05-01')->first();
        $this->assertNotNull($may);
        $this->assertSame(1600.0, (float) $may->amount);

        Carbon::setTestNow();
    }

    /**
     * Nutzermeldung: "Ich habe die Option 'Ausgleichsfunktion' gewählt, aber die
     * Tilgung wird entsprechend der letzten Buchung fortgesetzt." – reproduziert und
     * behebt den zugrunde liegenden Fehler: die vorherige Implementierung ordnete jede
     * gebuchte Kostenzeile ihrem Vorkommen per *exaktem* Datumsabgleich gegen die vom
     * System berechnete Vorkommnis-Sequenz zu. Eine echte, per Hand über "Kostenzeile
     * hinzufügen" erfasste Buchung landet aber so gut wie nie exakt auf diesem
     * berechneten Datum (das Formular schlägt dafür z. B. nur das Rechnungs-
     * Buchungsdatum vor) – ein einziger Tag Abweichung ließ den Datumsabgleich
     * fehlschlagen, wodurch weniger als zwei Punkte für die Regression übrig blieben
     * und sie stillschweigend auf "letzte Buchung" zurückfiel. Jetzt wird jede Buchung
     * ihrem *kalendarisch nächstgelegenen* Vorkommen zugeordnet (nearestOccurrenceIndex()),
     * genau wie beim Seeder.
     */
    public function test_a_booking_a_few_days_off_the_computed_occurrence_date_still_feeds_the_regression(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_REGRESSION, disableAmountCap: true);

        // Realistische, handeingegebene Buchungsdaten – keins davon landet exakt auf
        // dem 1. des jeweiligen Monats.
        $this->bookCost($invoice, $category, '2026-01-03', 100.0);
        $this->bookCost($invoice, $category, '2026-02-01', 100.5);
        $this->bookCost($invoice, $category, '2026-03-04', 101.0);

        $this->completeSeries($user, $invoice, $category, '2026-04-04');

        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();

        // Keine doppelten Zeilen für bereits gebuchte Monate: die 3 echten Buchungen plus
        // die fehlenden Termine bis zum Prognosehorizont (Apr 2026 … Dez 2027).
        $this->assertCount(24, $costs);
        $this->assertSame(1, $costs->filter(fn (Cost $c) => $c->period_date->format('Y-m') === '2026-03')->count());

        $apr = $costs->first(fn (Cost $c) => $c->period_date->format('Y-m') === '2026-04');
        $this->assertTrue($apr->isOpen());
        // Trend fortgeschrieben (101 + 0,5), nicht die eingefrorenen 101 der letzten
        // Buchung.
        $this->assertSame(101.5, (float) $apr->amount);

        Carbon::setTestNow();
    }

    /**
     * Zweiter, beim Reproduzieren des obigen Fehlers gefundener Fehler mit derselben
     * Ursache: completeSeries() erkannte eine bereits gebuchte Periode ebenfalls nur
     * per exaktem period_date-Abgleich als "schon vorhanden" – bei einem nicht exakt
     * treffenden Buchungsdatum legte es zusätzlich eine offene "Prognose"-Zeile für
     * dieselbe Periode an, die die Ausgaben dieses Monats verdoppelt hätte.
     */
    public function test_completing_the_series_does_not_duplicate_a_period_whose_booking_date_is_not_exact(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING, disableAmountCap: true);

        $this->bookCost($invoice, $category, '2026-01-31', 100.0);

        // Die Terminfolge beginnt am Buchungsdatum der Rechnung (1.1.); die Buchung vom
        // 31.1. deckt den Januar bereits ab.
        $this->completeSeries($user, $invoice, $category, '2026-03-01');

        // Nicht zwei Zeilen für Januar (die echte Buchung vom 31.1. plus eine
        // fälschlich zusätzlich generierte Prognosezeile zum 1.1.) – nur eine je
        // Periode, bis zum Prognosehorizont (Dez 2027).
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertCount(24, $costs);
        $this->assertSame(1, $costs->filter(fn (Cost $c) => $c->period_date->format('Y-m') === '2026-01')->count());
        $this->assertSame('2026-01-31', $costs->first()->period_date->toDateString());
        $this->assertFalse($costs->first()->isOpen());

        Carbon::setTestNow();
    }

    /**
     * Nutzermeldung nach dem vorherigen Fix: "Es funktioniert immer noch nicht." –
     * completeSeries() füllte bisher nur *fehlende* Perioden auf; eine bereits
     * bestehende, noch offene Prognosezeile aus einem früheren Lauf (z. B. Monate, die
     * generiert wurden, bevor die Rechnung überhaupt "variabel" war oder bevor der
     * Modus auf "Regression" umgestellt wurde) blieb für immer bei ihrem alten,
     * eingefrorenen Betrag stehen – ein bloßer Moduswechsel auf der Verwaltungsgruppe
     * konnte sich auf sie nie auswirken. Jetzt aktualisiert ein erneuter Aufruf von
     * "Serie vervollständigen" (auch ohne den Zeitraum zu erweitern) jede noch offene
     * Zeile auf den aktuell berechneten Prognosewert.
     */
    public function test_completing_the_series_again_refreshes_stale_open_rows_after_switching_modes(): void
    {
        Carbon::setTestNow('2026-01-15');
        [$user, $invoice, $category, $managementGroup] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING);

        // Erster Lauf, bevor die Rechnung überhaupt "variabel" ist: legt Jan-Apr als
        // offene Prognosezeilen zum nominalen Betrag (100) an.
        $this->completeSeries($user, $invoice, $category, '2026-04-01');
        $this->assertTrue(
            Cost::where('invoice_id', $invoice->id)->get()->every(fn (Cost $c) => (float) $c->amount === 100.0)
        );

        // Reale, ansteigende Buchungen für Jan-Mär bestätigen die bereits bestehenden
        // offenen Zeilen (wie es die Oberfläche tut) statt neue anzulegen – die
        // Rechnung wird dadurch "variabel" (Bedingung b: unterschiedliche Beträge).
        $this->confirmCost($invoice, '2026-01-01', 100.0, '2026-01-02');
        $this->confirmCost($invoice, '2026-02-01', 100.5, '2026-02-01');
        $this->confirmCost($invoice, '2026-03-01', 101.0, '2026-03-02');

        $managementGroup->update(['variable_cost_forecast_mode' => ManagementGroup::VARIABLE_FORECAST_REGRESSION]);

        // Erneuter Aufruf – ohne den Zeitraum zu erweitern – muss die längst
        // bestehende, noch offene April-Zeile auf den jetzt berechneten
        // Regressionswert auffrischen statt sie beim eingefrorenen nominalen Betrag
        // (100) zu belassen.
        $this->completeSeries($user, $invoice, $category, '2026-04-01');

        $apr = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-04-01')->first();
        $this->assertNotNull($apr);
        $this->assertTrue($apr->isOpen());
        $this->assertSame(101.5, (float) $apr->amount);

        // Keine Duplikate durch den erneuten Lauf: je Monat eine Zeile; da die Rechnung
        // jetzt schwankt, reicht die Prognose bis zum Horizont (Dez 2027).
        $costs = Cost::where('invoice_id', $invoice->id)->get();
        $this->assertCount(24, $costs);
        $this->assertSame(24, $costs->map(fn (Cost $c) => $c->period_date->format('Y-m'))->unique()->count());

        Carbon::setTestNow();
    }

    /**
     * Nutzerwunsch: "Die händische Aktualisierung ist fehleranfällig. Überlege dir was
     * anderes." – statt den Nutzer "Serie vervollständigen" erneut klicken zu lassen,
     * frischt CostController::update() jetzt automatisch die übrigen offenen Zeilen
     * derselben Kategorie auf, sobald eine Buchung tatsächlich bestätigt wird. Bildet
     * exakt den normalen Arbeitsablauf nach (Monat für Monat über die Oberfläche
     * bestätigen) – kein einziger Aufruf von "Serie vervollständigen" nach dem
     * allerersten.
     */
    public function test_confirming_a_booking_via_the_kostenzeile_form_automatically_refreshes_other_open_rows(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_REGRESSION, disableAmountCap: true);

        // Erster (und einziger) Aufruf von "Serie vervollständigen": legt Jan-Apr als
        // offene Prognosezeilen an.
        $this->completeSeries($user, $invoice, $category, '2026-04-01');

        $jan = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-01-01')->first();
        $feb = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-02-01')->first();
        $mar = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-03-01')->first();

        // Der Nutzer bestätigt die drei Monate nacheinander direkt über die "Kostenzeile
        // bearbeiten"-Oberfläche – kein manueller Zusatzschritt.
        foreach ([[$jan, 100.0, '2026-01-02'], [$feb, 100.5, '2026-02-01'], [$mar, 101.0, '2026-03-02']] as [$cost, $amount, $bookingDate]) {
            $this->actingAs($user)->put(route('costs.update', $cost), [
                'billing_interval_id' => $cost->billing_interval_id,
                'cost_category_id' => $category->id,
                'amount' => $amount,
                'booking_date' => $bookingDate,
            ])->assertRedirect(route('invoices.show', $invoice));
        }

        // April wurde durch die dritte Bestätigung automatisch auf den
        // Regressionswert aufgefrischt, ohne "Serie vervollständigen" erneut
        // aufzurufen.
        $apr = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-04-01')->first();
        $this->assertTrue($apr->isOpen());
        $this->assertSame(101.5, (float) $apr->amount);

        Carbon::setTestNow();
    }

    /**
     * Nutzerwunsch (Fortsetzung): der zweite Auslöser – die Verwaltungsgruppen-
     * Einstellung selbst wird geändert, ohne dass gleichzeitig neue Buchungen
     * eintreffen – muss ebenfalls automatisch wirken.
     */
    public function test_switching_the_managementgroup_mode_automatically_refreshes_stale_open_rows(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category, $managementGroup] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING, disableAmountCap: true);

        $this->completeSeries($user, $invoice, $category, '2026-04-01');

        foreach ([['2026-01-01', 100.0, '2026-01-02'], ['2026-02-01', 100.5, '2026-02-01'], ['2026-03-01', 101.0, '2026-03-02']] as [$periodDate, $amount, $bookingDate]) {
            $cost = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', $periodDate)->first();
            $this->actingAs($user)->put(route('costs.update', $cost), [
                'billing_interval_id' => $cost->billing_interval_id,
                'cost_category_id' => $category->id,
                'amount' => $amount,
                'booking_date' => $bookingDate,
            ])->assertRedirect(route('invoices.show', $invoice));
        }

        // Die letzte Bestätigung (März) hat April bereits automatisch auf den
        // last_booking-Wert (101) aufgefrischt.
        $apr = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-04-01')->first();
        $this->assertSame(101.0, (float) $apr->amount);

        // Jetzt wechselt der Nutzer den Modus – ohne irgendeine neue Buchung.
        $this->actingAs($user)->put(route('management-groups.update', $managementGroup), [
            'user_group_id' => $managementGroup->user_group_id,
            'description' => $managementGroup->description,
            'variable_cost_forecast_mode' => ManagementGroup::VARIABLE_FORECAST_REGRESSION,
        ])->assertRedirect(route('management-groups.show', $managementGroup));

        $apr->refresh();
        $this->assertTrue($apr->isOpen());
        $this->assertSame(101.5, (float) $apr->amount);

        Carbon::setTestNow();
    }

    /**
     * A deleted booking is also "new evidence" for the forecast (its history just
     * shrank): CostController::destroy() triggers the same automatic refresh as
     * store()/update().
     */
    public function test_deleting_a_booking_automatically_refreshes_the_forecast(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING, disableAmountCap: true);

        $this->completeSeries($user, $invoice, $category, '2026-04-01');

        foreach ([['2026-01-01', 100.0, '2026-01-02'], ['2026-02-01', 100.5, '2026-02-01'], ['2026-03-01', 101.0, '2026-03-02']] as [$periodDate, $amount, $bookingDate]) {
            $cost = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', $periodDate)->first();
            $this->actingAs($user)->put(route('costs.update', $cost), [
                'billing_interval_id' => $cost->billing_interval_id,
                'cost_category_id' => $category->id,
                'amount' => $amount,
                'booking_date' => $bookingDate,
            ])->assertRedirect(route('invoices.show', $invoice));
        }

        $apr = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-04-01')->first();
        $this->assertSame(101.0, (float) $apr->amount);

        // März wird wieder gelöscht (z. B. weil sie versehentlich falsch erfasst
        // wurde) – April muss automatisch wieder auf den jetzt letzten bekannten
        // Betrag (Februar, 100,5) zurückfallen, ohne dass "Serie vervollständigen"
        // erneut aufgerufen wird.
        $mar = Cost::where('invoice_id', $invoice->id)->whereDate('period_date', '2026-03-01')->first();
        $this->actingAs($user)->delete(route('costs.destroy', $mar))
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertNull(Cost::find($mar->id));

        $apr->refresh();
        $this->assertTrue($apr->isOpen());
        $this->assertSame(100.5, (float) $apr->amount);

        Carbon::setTestNow();
    }

    /**
     * Nutzermeldung: "Öffnen von .../management-groups/1 => Alles OK. Ich schalte auf
     * Ausgleichsfunktion. .../management-groups/1 => Ab September leer." – ein
     * gesäter Rechnung (der TestDataSeeder legt Cost-Zeilen direkt an, ohne je
     * `recurrence_start`/`recurrence_end` auf der Rechnung zu setzen, obwohl die
     * Zeilen selbst bis Ende nächsten Jahres reichen) hatte ihre komplette Zukunft
     * verloren, sobald irgendein automatischer Refresh (hier: der Moduswechsel)
     * zum ersten Mal lief: `completeSeries()` setzte `recurrence_end` mangels
     * Alternative auf "jetzt", wodurch jede spätere, bereits vorhandene Zeile als
     * "nicht mehr Teil der aktuellen Prognose" erschien und von der
     * Verwaisungs-Bereinigung gelöscht wurde. Muss jetzt nie mehr passieren, egal wie
     * die Rechnung ursprünglich befüllt wurde.
     */
    public function test_a_mode_switch_never_deletes_pre_existing_forecast_rows_reaching_beyond_now(): void
    {
        Carbon::setTestNow('2026-08-08');
        [$user, $invoice, $category, $managementGroup] = $this->makeMonthlyInvoice(ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING, disableAmountCap: true);

        // Bildet nach, wie der Seeder Kostenzeilen anlegt: direkt und ohne
        // recurrence_start/recurrence_end auf der Rechnung zu setzen, obwohl die
        // Zeilen selbst weit über "heute" hinausreichen (Buchungen bis August,
        // offene Prognose bis weit ins nächste Jahr).
        foreach (['2026-06-01', '2026-07-01', '2026-08-01'] as $bookedPeriod) {
            $this->bookCost($invoice, $category, $bookedPeriod, 100.0, $bookedPeriod);
        }
        foreach (['2026-09-01', '2026-10-01', '2027-11-01', '2027-12-01'] as $openPeriod) {
            $invoice->costs()->create([
                'costable_type' => $invoice->costable->getMorphClass(),
                'costable_id' => $invoice->costable->id,
                'billing_interval_id' => $invoice->costable->managementGroup->billingIntervals()->first()->id,
                'cost_category_id' => $category->id,
                'amount' => 100.0,
                'period_date' => $openPeriod,
            ]);
        }

        $invoice->refresh();
        $this->assertNull($invoice->recurrence_start);
        $this->assertNull($invoice->recurrence_end);
        $this->assertCount(7, Cost::where('invoice_id', $invoice->id)->get());

        // Der Nutzer wechselt den Modus – der einzige Auslöser in diesem Szenario.
        $this->actingAs($user)->put(route('management-groups.update', $managementGroup), [
            'user_group_id' => $managementGroup->user_group_id,
            'description' => $managementGroup->description,
            'variable_cost_forecast_mode' => ManagementGroup::VARIABLE_FORECAST_REGRESSION,
        ])->assertRedirect(route('management-groups.show', $managementGroup));

        // Keine der bereits vorhandenen, weit in der Zukunft liegenden Zeilen darf
        // verschwunden sein.
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertGreaterThanOrEqual(7, $costs->count());
        $this->assertNotNull($costs->first(fn (Cost $c) => $c->period_date->toDateString() === '2026-09-01'));
        $this->assertNotNull($costs->first(fn (Cost $c) => $c->period_date->toDateString() === '2027-12-01'));

        Carbon::setTestNow();
    }
}
