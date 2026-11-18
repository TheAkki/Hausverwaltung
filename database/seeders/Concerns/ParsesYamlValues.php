<?php

namespace Database\Seeders\Concerns;

use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Low-level, stateless helpers shared across TestDataSeeder's concerns: reading
 * "Prefix-*"/"Prefix" blocks out of a parsed YAML array, normalizing dates/decimals/
 * reference keys, and mapping German YAML labels to internal enum values.
 */
trait ParsesYamlValues
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function entriesMatching(array $data, string $prefix): array
    {
        return array_values($this->entriesMatchingWithKeys($data, $prefix));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>> keyed by the suffix after "{$prefix}-", or
     *                                             "" for a bare, unsuffixed "{$prefix}" key
     */
    private function entriesMatchingWithKeys(array $data, string $prefix): array
    {
        $entries = [];

        foreach ($data as $key => $value) {
            if (! is_array($value) || ! is_string($key)) {
                continue;
            }

            if ($key === $prefix) {
                $entries[''] = $value;
            } elseif (str_starts_with($key, "{$prefix}-")) {
                $entries[substr($key, strlen("{$prefix}-"))] = $value;
            }
        }

        return $entries;
    }

    /**
     * Like entriesMatchingWithKeys(), but additionally accepts the entries nested one
     * level deeper under an optional bare "{$prefix}:" grouping key (the documented
     * Konzept.md schema for "Mieter"/"Rechnungssteller": "[Mieter]: [Mieter-*]: …") –
     * mirroring the same "may be written either directly or wrapped" tolerance already
     * built for a Buchungen-Datei's own top-level "Buchungen:" key
     * (SeedsBuchungen::unwrapBuchungenFile()). Without this, a bare "{$prefix}:" wrapper
     * would itself be picked up by entriesMatchingWithKeys() as a single, bare (suffix "")
     * entry whose "data" is actually the whole group of individual entries – silently
     * producing zero real Mieter/Rechnungssteller instead of the entries inside it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function entriesMatchingWithKeysUnwrapped(array $data, string $prefix): array
    {
        $direct = $this->entriesMatchingWithKeys($data, $prefix);
        $wrapped = isset($direct['']) ? $this->entriesMatchingWithKeys($direct[''], $prefix) : [];

        return $wrapped !== [] ? $wrapped : $direct;
    }

    /**
     * Collects "{amountPrefix}-N"/"{categoryPrefix}-N" scalar pairs (e.g. "Betrag-1"/
     * "Kategorie-1"), unlike entriesMatchingWithKeys() which only collects nested arrays.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{0: float, 1: string}>
     */
    private function pairedEntries(array $data, string $amountPrefix, string $categoryPrefix): array
    {
        $pairs = [];
        $suffixesWithAmount = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, "{$amountPrefix}-") || is_array($value)) {
                continue;
            }

            $suffix = substr($key, strlen("{$amountPrefix}-"));
            $amount = $this->parseDecimal($value);
            $category = $data["{$categoryPrefix}-{$suffix}"] ?? null;

            if ($amount !== null && $category !== null && $category !== '') {
                $pairs[] = [$amount, (string) $category];
                $suffixesWithAmount[$suffix] = true;
            }
        }

        // A Kategorie-N without a matching Betrag-N still gets its category booked
        // (with amount 0), so it appears in the Ausgabenübersicht and can be filled in
        // with a real amount later, instead of being silently dropped.
        foreach ($data as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, "{$categoryPrefix}-") || is_array($value) || $value === '' || $value === null) {
                continue;
            }

            $suffix = substr($key, strlen("{$categoryPrefix}-"));

            if (isset($suffixesWithAmount[$suffix])) {
                continue;
            }

            $pairs[] = [0.0, (string) $value];
        }

        return $pairs;
    }

    /**
     * Maps the German YAML label for "Abrechnungsschlüssel" to the internal allocation_key
     * value used by ManagementGroupCostCategoryController/management_group_cost_category.
     */
    private function mapAllocationKey(?string $value): ?string
    {
        return match ($value) {
            'Personen' => 'person_count',
            'Fläche' => 'area',
            'Individual', 'Individuell' => 'individual',
            'Mieteinheit' => 'rental_unit',
            default => null,
        };
    }

    private function mapRentAdjustmentType(?string $value): string
    {
        return match ($value) {
            'Staffel' => 'stepped',
            'Index' => 'indexed',
            default => 'fixed',
        };
    }

    /**
     * "Niemals stillschweigend Daten überspringen": ein "Wiederholung"-Wert wird
     * unabhängig von Groß-/Kleinschreibung erkannt (Konzept.md dokumentiert die
     * YAML-Werte zwar durchgehend klein, aber "Quartal" ist als Substantiv im
     * Deutschen eigentlich großzuschreiben – ein Datenfehler wie in
     * "fn4-buchungen-2024/2025.yaml", wo "Wiederholung: Quartal" (Großbuchstabe)
     * zuvor durch den case-sensitiven Vergleich still auf "einmalig" zurückfiel,
     * statt wie gemeint eine vierteljährliche Rechnung mit nur EINER statt vier
     * Kostenzeilen anzulegen). Ein Wert, der nach dem Normalisieren immer noch zu
     * keinem bekannten Schlüsselwort passt (z. B. ein echter Tippfehler), bricht
     * das Seeden dagegen ab, statt ebenfalls lautlos als "einmalig" zu gelten.
     */
    private function mapPeriodicity(?string $value): string
    {
        if ($value === null || $value === '') {
            return Invoice::PERIODICITY_ONCE;
        }

        return match (mb_strtolower(trim($value))) {
            'einmalig' => Invoice::PERIODICITY_ONCE,
            'wöchentlich' => Invoice::PERIODICITY_WEEKLY,
            '2-wöchentlich' => Invoice::PERIODICITY_BIWEEKLY,
            'monatlich' => Invoice::PERIODICITY_MONTHLY,
            'quartal', 'quartalsweise' => Invoice::PERIODICITY_QUARTERLY,
            'jährlich' => Invoice::PERIODICITY_YEARLY,
            // "händisch" (Invoice::PERIODICITY_MANUAL) wird hier bewusst NICHT als
            // Wiederholung akzeptiert: der Seeder baut dafür keine Occurrences auf,
            // eine so "erfolgreich" gemappte Rechnung bliebe eine kaputte Attrappe
            // ohne jede Kostenzeile statt eines klaren Fehlers beim Seeden.
            default => throw new \RuntimeException("Unbekannte \"Wiederholung\": \"{$value}\". Erlaubt sind \"einmalig\", \"wöchentlich\", \"2-wöchentlich\", \"monatlich\", \"quartal\" oder \"jährlich\" (\"händisch\" kann der Testdaten-Seeder nicht abbilden)."),
        };
    }

    private function normalizeReferenceKey(string $value, string $prefix): string
    {
        return str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
    }

    /**
     * Resolves a local asset path referenced from seeddata.yaml (a template file, a
     * header image, or a Buchungen-Datei) to an existing file. Tries, in order: the
     * path as-is (absolute), relative to the project root, and relative to ".claude/"
     * (where private assets like templates/logos are commonly kept and gitignored).
     *
     * Only ever called with a non-empty reference the author actually gave (every
     * caller already treats an *absent* reference as "optional data left out" and
     * returns early before reaching here) – so a reference that doesn't resolve to a
     * real file is a misconfiguration (e.g. a typo in the path), not omitted data, and
     * must fail loudly instead of being silently ignored.
     *
     * @throws \RuntimeException if no candidate path exists
     */
    private function resolveLocalAssetPath(string $path): string
    {
        $dataDirectory = dirname((string) config('seeding.data_file'));

        foreach ([$path, base_path($path), $dataDirectory.'/'.$path, base_path('.claude/'.$path)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException("Referenzierte Datei nicht gefunden: \"{$path}\".");
    }

    /**
     * Accepts either a plain number or a German-formatted decimal string (e.g. "108,30").
     */
    private function parseDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return (float) $value;
    }

    /**
     * Normalizes a Beginn/Ende value to an ISO "Y-m-d" string. Accepts ISO dates or
     * dotted German dates with a 2- or 4-digit year (e.g. "07.05.2022", "1.11.25").
     *
     * PHP's general date parser is unreliable for this: strtotime("1.11.25") silently
     * returns *today's date* instead of failing, because a single-digit day combined
     * with a 2-digit year confuses its d.m.y heuristic. Explicit formats sidestep that.
     */
    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Symfony YAML auto-converts unquoted ISO dates (e.g. "2022-06-01") into a Unix
        // timestamp int, which must be handled before any string-based parsing below.
        if (is_int($value)) {
            return Carbon::createFromTimestamp($value, 'UTC')->toDateString();
        }

        $value = trim((string) $value);

        // Expand a 2-digit year (e.g. "1.11.25") to 4 digits first: Carbon's "y" format
        // char parses it as literal year 0025 rather than applying a century pivot.
        $value = preg_replace('/^(\d{1,2})\.(\d{1,2})\.(\d{2})$/', '$1.$2.20$3', $value);

        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            try {
                return Carbon::createFromFormat('!'.$format, $value)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return Carbon::parse($value)->toDateString();
    }

    /**
     * "-n" (e.g. "Kategorie-n", "Betrag-n") is only placeholder notation used in the
     * Konzept.md schema documentation to mean "any numeric suffix" – never a literal
     * key to write in real seed data (the real suffix is always a concrete number,
     * e.g. "Kategorie-2"). Written literally, it silently fails to pair up with its
     * "Betrag-N"/"Kategorie-N" counterpart (see pairedEntries()), so a whole amount can
     * go missing without any error. Checked recursively since it can appear at any
     * nesting depth (Zuordnung, Buchung-*, …).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \RuntimeException if any key ends in "-n" (case-insensitive)
     */
    private function assertNoLiteralNSuffixKeys(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/-n$/i', $key) === 1) {
                throw new \RuntimeException(
                    "Ungültiger Schlüssel \"{$key}\" in den Seed-Daten: \"-n\" ist nur Platzhalter-Notation ".
                    'in der Konzept.md-Dokumentation (z. B. "Kategorie-n" für "eine beliebige Kategorie-Nummer"), '.
                    'kein literaler Schlüssel – gemeint ist vermutlich eine konkrete Zahl (z. B. "Kategorie-2").'
                );
            }

            if (is_array($value)) {
                $this->assertNoLiteralNSuffixKeys($value);
            }
        }
    }

    private function uniqueEmailFor(string $name): string
    {
        $base = Str::slug($name, '.') ?: 'account';
        $email = "{$base}@example.com";
        $suffix = 1;

        while (User::where('email', $email)->exists()) {
            $suffix++;
            $email = "{$base}{$suffix}@example.com";
        }

        return $email;
    }
}
