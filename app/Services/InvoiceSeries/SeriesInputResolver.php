<?php

namespace App\Services\InvoiceSeries;

use App\Models\BillingInterval;
use App\Models\CostCategory;
use App\Models\ManagementGroup;
use App\Models\User;
use App\Services\GroupOwnership\GroupOwnership;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Übersetzt die Eingaben der REST-API (Aufteilungen, Betragsintervalle, angeheftete
 * Abrechnungsintervalle) in CostAllocation/CostSegment und prüft dabei die fachlichen
 * Regeln (Konzept.md "### Rechnungen"):
 *
 * - Kategorien müssen für den Benutzer sichtbar sein und systemweit sein oder der
 *   Benutzergruppe der Verwaltungsgruppe gehören (GroupOwnership),
 *   Abrechnungsintervalle zur Verwaltungsgruppe gehören.
 * - Ohne Betrag gilt bei genau einem Anteil der ganze Betrag, sonst 0 (der Betrag
 *   kommt später mit den Buchungen).
 * - "Betragsüberprüfung": Ist mindestens ein Betrag angegeben, muss die Summe dem
 *   (wirksamen) Betrag entsprechen – außer die Prüfung ist abgeschaltet.
 * - Betragsintervalle sind lückenlos und chronologisch, nur das letzte darf offen
 *   sein; entweder hat jedes eine eigene Aufteilung oder keines.
 */
final class SeriesInputResolver
{
    public function __construct(
        private readonly ManagementGroup $managementGroup,
        private readonly User $user,
        private readonly bool $checkSums,
    ) {}

    /**
     * @param  list<array{cost_category_id: int|string, amount?: float|int|string|null, billing_interval_id?: int|string|null}>  $rows
     * @param  float  $amount  Betrag eines einzigen Anteils ohne eigenen Betrag
     * @param  float|null  $sumTarget  Sollsumme der Betragsüberprüfung (Standard: $amount), z. B. der wirksame Rechnungsbetrag
     * @return list<CostAllocation>
     */
    public function allocations(array $rows, float $amount, string $field, ?float $sumTarget = null): array
    {
        $allocations = [];

        foreach (array_values($rows) as $i => $row) {
            $allocations[] = new CostAllocation(
                $this->shareAmount($row, $amount, count($rows)),
                $this->category($row['cost_category_id'], "{$field}.{$i}.cost_category_id"),
                $this->interval($row['billing_interval_id'] ?? null, "{$field}.{$i}.billing_interval_id"),
            );
        }

        $this->assertSum($rows, $allocations, $sumTarget ?? $amount, $field);

        return $allocations;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $sharedAllocations  Aufteilung der Rechnung, wenn die Intervalle keine eigene haben
     * @return non-empty-list<CostSegment>
     */
    public function segments(array $rows, array $sharedAllocations, string $field): array
    {
        $rows = array_values($rows);
        $this->assertContiguous($rows, $field);
        $withOwn = count(array_filter($rows, fn (array $row) => ! empty($row['allocations'])));

        if (($withOwn > 0 && $withOwn < count($rows)) || ($withOwn > 0 && $sharedAllocations !== [])) {
            $this->fail($field, 'Entweder hat jedes Betragsintervall eine eigene Aufteilung oder keines (dann gilt die der Rechnung).');
        }

        if ($withOwn === 0 && $sharedAllocations === []) {
            $this->fail($field, 'Ohne eigene Aufteilung der Betragsintervalle braucht die Rechnung eine Aufteilung (allocations).');
        }

        return array_map(fn (array $row, int $i) => new CostSegment(
            Carbon::parse($row['start_date'])->toDateString(),
            isset($row['end_date']) ? Carbon::parse($row['end_date'])->toDateString() : null,
            (float) $row['amount'],
            $row['note'] ?? null,
            $this->allocations($row['allocations'] ?? $sharedAllocations, (float) $row['amount'], "{$field}.{$i}.allocations"),
        ), $rows, array_keys($rows));
    }

    public function interval(int|string|null $id, string $field): ?BillingInterval
    {
        if ($id === null || $id === '') {
            return null;
        }

        $interval = $this->managementGroup->billingIntervals()->whereKey($id)->first();

        if ($interval === null) {
            $this->fail($field, 'Das Abrechnungsintervall gehört nicht zur Verwaltungsgruppe dieser Rechnung.');
        }

        return $interval;
    }

    public function category(int|string $id, string $field): CostCategory
    {
        $category = CostCategory::whereKey($id)->visibleTo($this->user)->first();

        if ($category === null) {
            $this->fail($field, 'Die Kategorie ist nicht vorhanden oder gehört keiner deiner Gruppen.');
        }

        GroupOwnership::assertCategoryFits($category, $this->managementGroup, $field);

        return $category;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function shareAmount(array $row, float $amount, int $shares): float
    {
        if (isset($row['amount']) && $row['amount'] !== '') {
            return (float) $row['amount'];
        }

        return $shares === 1 ? $amount : 0.0;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<CostAllocation>  $allocations
     */
    private function assertSum(array $rows, array $allocations, float $amount, string $field): void
    {
        $hasExplicitAmount = collect($rows)->contains(fn (array $row) => isset($row['amount']) && $row['amount'] !== '');

        if (! $this->checkSums || ! $hasExplicitAmount) {
            return;
        }

        $sum = round(array_sum(array_map(fn (CostAllocation $allocation) => $allocation->amount, $allocations)), 2);

        if ($sum !== round($amount, 2)) {
            $this->fail($field, sprintf(
                'Die Summe der Anteile (%s) entspricht nicht dem Betrag (%s). Mit "disable_amount_cap" abschaltbar.',
                number_format($sum, 2, ',', '.'),
                number_format($amount, 2, ',', '.'),
            ));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertContiguous(array $rows, string $field): void
    {
        $last = array_key_last($rows);

        foreach ($rows as $i => $row) {
            if (! isset($row['end_date']) && $i !== $last) {
                $this->fail("{$field}.{$i}.end_date", 'Nur das letzte Betragsintervall darf offen (ohne Ende) sein.');
            }

            if ($i > 0 && Carbon::parse($rows[$i - 1]['end_date'])->addDay()->toDateString() !== Carbon::parse($row['start_date'])->toDateString()) {
                $this->fail("{$field}.{$i}.start_date", 'Betragsintervalle müssen lückenlos aufeinander folgen (Beginn = Ende des vorigen + 1 Tag).');
            }
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
