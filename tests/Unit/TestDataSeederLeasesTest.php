<?php

namespace Tests\Unit;

use App\Models\Lease;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\RentPaymentGenerator;
use Database\Seeders\TestDataSeeder;

/**
 * Covers SeedsLeases: Mietvertrag and Mietintervall seeding, including Vorvertrag
 * handling and date parsing.
 */
class TestDataSeederLeasesTest extends TestDataSeederTestCase
{
    public function test_it_creates_leases_with_multiple_tenants_from_yaml(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Lease Seed User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Lease Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Mietvertragsweg 1"
                    PLZ: 44444
                    Stadt: "Mietstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 70
                        Beschreibung: "Whg A"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            MieterId-2: Mieter-2
                            Beginn: 2026-03-01
                            Ende: null
                            Mietpreistyp: "Fix"

                            Intervall-0:
                                Ende: null
                                Kaltmiete: 500
                                Nebenkosten: 100
                                Personen: 2

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"

            Mieter-2:
                Vorname: "Sina"
                Name: "Zweite"
                Straße: "B-Weg 2"
                PLZ: 66666
                Ort: "B-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $rentalUnit = RentalUnit::where('description', 'Whg A')->first();
        $this->assertNotNull($rentalUnit);

        $lease = Lease::where('rental_unit_id', $rentalUnit->id)->first();
        $this->assertNotNull($lease);
        $this->assertSame('2026-03-01', $lease->start_date->toDateString());
        $this->assertNull($lease->end_date);
        $this->assertSame('fixed', $lease->rent_adjustment_type);
        $this->assertSame(500.0, (float) $lease->cold_rent);

        // The single "Intervall-0" entry should map onto the Mietintervall the
        // LeaseObserver auto-creates, not create a second, separate one.
        $this->assertSame(1, $lease->leaseIntervals()->count());
        $interval = $lease->leaseIntervals()->first();
        $this->assertSame(500.0, (float) $interval->rent_amount);
        $this->assertSame(100.0, (float) $interval->utility_advance_payment);
        $this->assertSame(2, $interval->occupant_count);

        $tenantNames = $lease->tenants->map->fullName()->sort()->values()->all();
        $this->assertSame(['Sina Zweite', 'Tom Erste'], $tenantNames);
    }

    public function test_kaltmiete_and_nebenkosten_on_the_mietvertrag_are_used_as_interval_defaults(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Default Rent Seed User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Default Rent Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Standardweg 1"
                    PLZ: 11223
                    Stadt: "Standardstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 65
                        Beschreibung: "Whg C"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2022-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 723,27
                            Nebenkosten: 346,73

                            Intervall-0:
                                Personen: 3

            Mieter-1:
                Vorname: "Paul"
                Name: "Beispiel"
                Straße: "E-Weg 5"
                PLZ: 12121
                Ort: "E-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $lease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('description', 'Whg C'))->first();
        $this->assertNotNull($lease);
        $this->assertSame(723.27, (float) $lease->cold_rent);

        $interval = $lease->leaseIntervals()->first();
        $this->assertSame(723.27, (float) $interval->rent_amount);
        $this->assertSame(346.73, (float) $interval->utility_advance_payment);
        $this->assertSame(3, $interval->occupant_count);
    }

    public function test_it_appends_further_mietintervalle_and_closes_the_previous_one(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Interval Seed User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Interval Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Intervallweg 1"
                    PLZ: 77777
                    Stadt: "Intervallstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 60
                        Beschreibung: "Whg B"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2022-01-01
                            Mietpreistyp: "Staffel"

                            Intervall-0:
                                Kaltmiete: 600
                                Nebenkosten: 150
                                Personen: 1

                            Intervall-1:
                                Beginn: 2023-01-01
                                Kaltmiete: 650
                                Nebenkosten: 160
                                Personen: 1

            Mieter-1:
                Vorname: "Otto"
                Name: "Dritter"
                Straße: "C-Weg 3"
                PLZ: 88888
                Ort: "C-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $lease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('description', 'Whg B'))->first();
        $this->assertNotNull($lease);
        $this->assertSame('stepped', $lease->rent_adjustment_type);
        $this->assertSame(600.0, (float) $lease->cold_rent);

        $intervals = $lease->leaseIntervals()->orderBy('start_date')->get();
        $this->assertCount(2, $intervals);

        $this->assertSame('2022-01-01', $intervals[0]->start_date->toDateString());
        $this->assertSame('2022-12-31', $intervals[0]->end_date->toDateString());
        $this->assertSame(600.0, (float) $intervals[0]->rent_amount);

        $this->assertSame('2023-01-01', $intervals[1]->start_date->toDateString());
        $this->assertNull($intervals[1]->end_date);
        $this->assertSame(650.0, (float) $intervals[1]->rent_amount);
    }

    /**
     * Regression test: a terminated Mietvertrag ("Ende" set) whose Intervall-* entries
     * don't repeat that "Ende" on the last (or only) interval used to leave that interval
     * "offen" (end_date null) – seedLeaseIntervals() unconditionally overwrote it from the
     * Intervall's own (missing) "Ende" instead of falling back to the Mietvertrag's end
     * date. RentPaymentGenerator then kept generating rent payments for that unit far
     * beyond the lease's actual end.
     */
    public function test_the_final_intervall_of_a_terminated_lease_without_its_own_ende_inherits_the_mietvertrags_end_date(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Ended Interval User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Ended Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Endeweg 1"
                    PLZ: 66666
                    Stadt: "Endestadt"

                    Mieteinheit-1:
                        Typ: "Stellplatz"
                        Abrechnungsrelevant: false

                        Mietvertrag-0:
                            MieterId: Mieter-1
                            Beginn: 01.03.2024
                            Ende: 31.10.2025
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30

                            Intervall-0:
                                Personen: 0

            Mieter-1:
                Vorname: "Otto"
                Name: "Normal"
                Straße: "C-Weg 3"
                PLZ: 88888
                Ort: "C-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $lease = Lease::whereHas('rentalUnit.managementUnit', fn ($q) => $q->where('street', 'Endeweg 1'))->first();
        $this->assertNotNull($lease);
        $this->assertSame('2025-10-31', $lease->end_date->toDateString());

        $interval = $lease->leaseIntervals()->first();
        $this->assertSame('2025-10-31', $interval->end_date->toDateString());

        // RentPaymentGenerator darf für diesen Mietvertrag keine Mietzahlungen nach dessen
        // Ende mehr anlegen.
        app(RentPaymentGenerator::class)->generate($interval);
        $this->assertSame(0, $interval->rentPayments()->where('period_date', '>', '2025-10-31')->count());
    }

    /**
     * Regression test: fixing seedLeaseIntervals() (see test above) to correctly close the
     * final Intervall of a terminated lease must also retroactively prune any still-open
     * RentPayment rows already generated beyond that date by an earlier (buggy) seeder run
     * – already-booked/confirmed rows are left untouched.
     */
    public function test_reseeding_after_the_fix_prunes_stale_open_rent_payments_beyond_the_lease_end(): void
    {
        $yaml = <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Ended Interval User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Ended Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Endeweg 1"
                    PLZ: 66666
                    Stadt: "Endestadt"

                    Mieteinheit-1:
                        Typ: "Stellplatz"
                        Abrechnungsrelevant: false

                        Mietvertrag-0:
                            MieterId: Mieter-1
                            Beginn: 01.03.2024
                            Ende: 31.10.2025
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30

                            Intervall-0:
                                Personen: 0

            Mieter-1:
                Vorname: "Otto"
                Name: "Normal"
                Straße: "C-Weg 3"
                PLZ: 88888
                Ort: "C-Stadt"
        YAML;

        // Simulate the pre-fix state: an interval left "offen" (as the old, buggy seeder
        // would have produced) with rent payments already generated well past the lease's
        // actual end – one of them already booked/confirmed by a user.
        file_put_contents($this->path, $yaml);
        $this->seed(TestDataSeeder::class);

        $lease = Lease::whereHas('rentalUnit.managementUnit', fn ($q) => $q->where('street', 'Endeweg 1'))->first();
        $interval = $lease->leaseIntervals()->first();
        $interval->update(['end_date' => null]);
        app(RentPaymentGenerator::class)->generate($interval);

        $this->assertGreaterThan(0, $interval->rentPayments()->where('period_date', '>', '2025-10-31')->count());

        $confirmedStale = $interval->rentPayments()->where('period_date', '>', '2025-10-31')->first();
        $confirmedStale->update(['booking_date' => $confirmedStale->period_date]);

        // Re-seeding now applies the fix: the interval is closed again, and stale still-
        // open rent payments beyond the lease's end are pruned – but the one already
        // confirmed above is left untouched.
        $this->seed(TestDataSeeder::class);

        $interval->refresh();
        $this->assertSame('2025-10-31', $interval->end_date->toDateString());
        $this->assertSame(1, $interval->rentPayments()->where('period_date', '>', '2025-10-31')->count());
        $this->assertSame($confirmedStale->id, $interval->rentPayments()->where('period_date', '>', '2025-10-31')->first()->id);
    }

    public function test_it_reads_the_notiz_field_on_an_intervall(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Interval Notiz User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Interval Notiz Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Notizweg 1"
                    PLZ: 34567
                    Stadt: "Notizstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 45
                        Beschreibung: "Whg E"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 400
                            Nebenkosten: 90

                            Intervall-0:
                                Personen: 2
                                Notiz: "Sondervereinbarung Garten"

            Mieter-1:
                Vorname: "Nora"
                Name: "Sechste"
                Straße: "F-Weg 6"
                PLZ: 13579
                Ort: "F-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $lease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('description', 'Whg E'))->first();
        $this->assertNotNull($lease);

        $interval = $lease->leaseIntervals()->first();
        $this->assertSame('Sondervereinbarung Garten', $interval->notes);
    }

    public function test_an_intervall_that_only_changes_nebenkosten_keeps_the_previous_intervalls_kaltmiete(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Carry Forward User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Carry Forward Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Fortschreibweg 1"
                    PLZ: 65432
                    Stadt: "Fortschreibstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 60
                        Beschreibung: "Whg F"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2024-04-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 488,72
                            Nebenkosten: 179,40

                            Intervall-0:
                                Personen: 1

                            Intervall-1:
                                Personen: 1
                                Beginn: 2025-10-01
                                Kaltmiete: 517,72

                            Intervall-2:
                                Personen: 1
                                Beginn: 2025-11-01
                                Nebenkosten: 191,36

            Mieter-1:
                Vorname: "Otto"
                Name: "Siebte"
                Straße: "G-Weg 7"
                PLZ: 97531
                Ort: "G-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $lease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('description', 'Whg F'))->first();
        $this->assertNotNull($lease);

        $intervals = $lease->leaseIntervals()->orderBy('start_date')->get();
        $this->assertCount(3, $intervals);

        // The rent increase from Intervall-1 must carry forward into Intervall-2, which
        // only changes Nebenkosten and must not fall back to the Mietvertrag-level default.
        $this->assertSame(517.72, (float) $intervals[2]->rent_amount);
        $this->assertSame(191.36, (float) $intervals[2]->utility_advance_payment);
    }

    public function test_an_intervall_that_omits_personen_keeps_the_previous_intervalls_occupant_count(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Occupant Carry Forward User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Occupant Carry Forward Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Personenweg 1"
                    PLZ: 65433
                    Stadt: "Personenstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 60
                        Beschreibung: "Whg P"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2024-04-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 488,72
                            Nebenkosten: 179,40

                            Intervall-0:
                                Personen: 3

                            Intervall-1:
                                Beginn: 2025-10-01
                                Nebenkosten: 191,36

            Mieter-1:
                Vorname: "Otto"
                Name: "Achte"
                Straße: "G-Weg 8"
                PLZ: 97532
                Ort: "G-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $lease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('description', 'Whg P'))->first();
        $this->assertNotNull($lease);

        $intervals = $lease->leaseIntervals()->orderBy('start_date')->get();
        $this->assertCount(2, $intervals);

        // Intervall-1 only changes Nebenkosten and doesn't declare "Personen" at all – it
        // must inherit the occupant count of 3 from Intervall-0, not fall back to a
        // hardcoded default of 1.
        $this->assertSame(3, $intervals[0]->occupant_count);
        $this->assertSame(3, $intervals[1]->occupant_count);
        $this->assertSame(191.36, (float) $intervals[1]->utility_advance_payment);
    }

    public function test_it_parses_dotted_dates_with_a_single_digit_day_and_two_digit_year(): void
    {
        // Regression test: PHP's strtotime("1.11.25") silently resolves to *today's
        // date* instead of failing, since a single-digit day plus a 2-digit year
        // confuses its d.m.y heuristic. The seeder must sidestep that via explicit formats.
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Ambiguous Date User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Ambiguous Date Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Datumsweg 1"
                    PLZ: 76543
                    Stadt: "Datumsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 40
                        Beschreibung: "Whg G"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2022-06-01
                            Ende: 31.10.25
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500
                            Nebenkosten: 100

                        Mietvertrag-2:
                            MieterId: Mieter-1
                            Beginn: 1.11.25
                            Mietpreistyp: "Fix"
                            Kaltmiete: 550
                            Nebenkosten: 110

            Mieter-1:
                Vorname: "Paul"
                Name: "Achte"
                Straße: "H-Weg 8"
                PLZ: 86420
                Ort: "H-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $rentalUnit = RentalUnit::where('description', 'Whg G')->first();
        $this->assertNotNull($rentalUnit);

        $leases = $rentalUnit->leases()->orderBy('start_date')->get();
        $this->assertCount(2, $leases);

        $this->assertSame('2022-06-01', $leases[0]->start_date->toDateString());
        $this->assertSame('2025-10-31', $leases[0]->end_date->toDateString());

        $this->assertSame('2025-11-01', $leases[1]->start_date->toDateString());
        $this->assertNull($leases[1]->end_date);
    }

    public function test_a_mietvertrag_flagged_as_vorvertrag_is_seeded_as_a_pre_contract(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Vorvertrag Seed User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Vorvertrag Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Vorvertragsweg 1"
                    PLZ: 44444
                    Stadt: "Mietstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 70
                        Abrechnungsrelevant: true

                        Mietvertrag-0:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Ende: 2026-01-31
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500
                            Vorvertrag: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-02-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $rentalUnit = RentalUnit::whereHas('managementUnit', fn ($q) => $q->where('street', 'Vorvertragsweg 1'))->first();
        $pre = Lease::where('rental_unit_id', $rentalUnit->id)->whereDate('start_date', '2026-01-01')->first();
        $main = Lease::where('rental_unit_id', $rentalUnit->id)->whereDate('start_date', '2026-02-01')->first();

        $this->assertTrue($pre->is_pre_contract);
        $this->assertFalse($main->is_pre_contract);
    }

    /**
     * Konzept.md "### Mietvertrag": a Mietvertrag can be identified either by its own
     * MieterId(-*) or by a "Mietvertrag: <ref>" coupling to another Mieteinheit's
     * Mietvertrag of the same Verwaltungseinheit (e.g. a Garage's contract coupled to
     * the tenant's Wohnung contract) – the coupled Mietvertrag then inherits that
     * Mietvertrag's tenant(s).
     */
    public function test_a_mietvertrag_coupled_by_reference_inherits_the_referenced_mietvertrags_tenants(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kopplung User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kopplung Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kopplungsweg 1"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

                    Mieteinheit-2:
                        Typ: "Garage"
                        Abrechnungsrelevant: false

                        Mietvertrag-1:
                            Mietvertrag: "Mieteinheit-1.Mietvertrag-1"
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $wohnungLease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('type', 'Wohnung'))->first();
        $garageLease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('type', 'Garage'))->first();

        $this->assertNotNull($wohnungLease);
        $this->assertNotNull($garageLease);
        $this->assertSame(30.0, (float) $garageLease->cold_rent);
        $this->assertSame(
            $wohnungLease->tenants->pluck('id')->sort()->values()->all(),
            $garageLease->tenants->pluck('id')->sort()->values()->all()
        );

        // Persisted so the Steuerübersicht can fold the Garage's income into the
        // Wohnung's row instead of listing it separately (Konzept.md "### Mietvertrag").
        $this->assertSame($wohnungLease->id, $garageLease->coupled_to_lease_id);

        // Reseeding must keep the coupling in place (idempotent), not clear it.
        $this->seed(TestDataSeeder::class);
        $this->assertSame($wohnungLease->id, $garageLease->fresh()->coupled_to_lease_id);
    }

    /**
     * The coupling reference must resolve regardless of which Mieteinheit is written
     * first in the YAML – here the referencing Garage (Mieteinheit-1) appears BEFORE
     * the referenced, abrechnungsrelevante Wohnung (Mieteinheit-2).
     */
    public function test_a_mietvertrag_coupled_reference_resolves_even_when_the_referencing_mieteinheit_comes_first_in_the_yaml(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kopplung Reihenfolge User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kopplung Reihenfolge Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kopplungsweg 2"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Garage"
                        Abrechnungsrelevant: false

                        Mietvertrag-1:
                            Mietvertrag: "Mieteinheit-2.Mietvertrag-1"
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30

                    Mieteinheit-2:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $wohnungLease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('type', 'Wohnung'))->first();
        $garageLease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('type', 'Garage'))->first();

        $this->assertNotNull($garageLease);
        $this->assertSame(
            $wohnungLease->tenants->pluck('id')->sort()->values()->all(),
            $garageLease->tenants->pluck('id')->sort()->values()->all()
        );
    }

    public function test_a_mietvertrag_with_neither_mieterid_nor_a_mietvertrag_reference_throws(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kopplung Fehler User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kopplung Fehler Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kopplungsweg 3"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Garage"
                        Abrechnungsrelevant: false

                        Mietvertrag-1:
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->seed(TestDataSeeder::class);
    }

    /**
     * Regression guard for Nutzerfeedback "Niemals stillschweigend Daten
     * überspringen": a Mietvertrag whose MieterId(-*) references a Mieter-Key that was
     * never actually seeded used to silently vanish (return null), instead of aborting.
     */
    public function test_a_mietvertrag_referencing_an_unknown_mieterid_throws(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Unbekannte MieterId User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Unbekannte MieterId Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-99
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->seed(TestDataSeeder::class);
    }

    public function test_a_mietvertrag_without_a_beginn_throws(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Ohne Beginn User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Ohne Beginn Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

            Mieter-1:
                Name: "Nachname"
                Straße: "Weg 2"
                PLZ: 22222
                Ort: "Stadt2"
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->seed(TestDataSeeder::class);
    }

    public function test_an_abrechnungsrelevante_mieteinheit_using_a_mietvertrag_reference_instead_of_mieterid_throws(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kopplung Fehler User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kopplung Fehler Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kopplungsweg 4"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            Mietvertrag: "Mieteinheit-2.Mietvertrag-1"
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

                    Mieteinheit-2:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->seed(TestDataSeeder::class);
    }

    public function test_a_mietvertrag_reference_to_a_non_abrechnungsrelevante_mieteinheit_throws(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kopplung Fehler User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kopplung Fehler Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kopplungsweg 5"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Garage"
                        Abrechnungsrelevant: false

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30

                    Mieteinheit-2:
                        Typ: "Stellplatz"
                        Abrechnungsrelevant: false

                        Mietvertrag-1:
                            Mietvertrag: "Mieteinheit-1.Mietvertrag-1"
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 20

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->seed(TestDataSeeder::class);
    }

    public function test_a_mietvertrag_reference_within_the_same_mieteinheit_throws(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kopplung Fehler User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kopplung Fehler Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kopplungsweg 6"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

                        Mietvertrag-2:
                            Mietvertrag: "Mietvertrag-1"
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->seed(TestDataSeeder::class);
    }

    public function test_a_mietvertrag_reference_to_another_verwaltungseinheit_throws(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kopplung Fehler User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kopplung Fehler Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kopplungsweg 7"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Garage"
                        Abrechnungsrelevant: false

                        Mietvertrag-1:
                            Mietvertrag: "Verwaltungseinheit-2.Mieteinheit-1.Mietvertrag-1"
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30

                Verwaltungseinheit-2:
                    Straße: "Kopplungsweg 8"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->seed(TestDataSeeder::class);
    }

    /**
     * A reference may drop leading segments (Verwaltungsgruppe/-einheit/Mieteinheit) as
     * long as it stays unambiguous – here just the bare Mietvertrag suffix, with no
     * other Mieteinheit of this Verwaltungseinheit having a "Mietvertrag-1".
     */
    public function test_a_shortened_mietvertrag_reference_without_the_mieteinheit_segment_resolves_when_unambiguous(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kopplung Kurzform User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kopplung Kurzform Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kopplungsweg 9"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

                    Mieteinheit-2:
                        Typ: "Garage"
                        Abrechnungsrelevant: false

                        Mietvertrag-1:
                            Mietvertrag: "Mietvertrag-1"
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $wohnungLease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('type', 'Wohnung'))->first();
        $garageLease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('type', 'Garage'))->first();

        $this->assertNotNull($garageLease);
        $this->assertSame(
            $wohnungLease->tenants->pluck('id')->sort()->values()->all(),
            $garageLease->tenants->pluck('id')->sort()->values()->all()
        );
    }

    /**
     * Konzept.md "### Detailbild Mieteinheit": "Der Referenzierende Mietvertrag endet
     * spätestens mit dem referenzierten Mietvertrag." – a coupled Mietvertrag ending
     * AFTER the one it references must be rejected.
     */
    public function test_a_coupled_mietvertrag_ending_after_its_referenced_mietvertrag_throws(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kopplung Enddatum User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kopplung Enddatum Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kopplungsweg 10"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Ende: 2026-06-30
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

                    Mieteinheit-2:
                        Typ: "Garage"
                        Abrechnungsrelevant: false

                        Mietvertrag-1:
                            Mietvertrag: "Mieteinheit-1.Mietvertrag-1"
                            Beginn: 2026-01-01
                            Ende: 2026-12-31
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->seed(TestDataSeeder::class);
    }

    /**
     * The same "endet spätestens" rule also rejects a coupled Mietvertrag left
     * open-ended (no "Ende") while its referenced Mietvertrag DOES have one – an
     * open end implies outliving any fixed end.
     */
    public function test_an_open_ended_coupled_mietvertrag_throws_when_its_referenced_mietvertrag_has_an_end_date(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kopplung Enddatum User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kopplung Enddatum Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kopplungsweg 11"
                    PLZ: 12345
                    Stadt: "Kopplungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Ende: 2026-06-30
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

                    Mieteinheit-2:
                        Typ: "Garage"
                        Abrechnungsrelevant: false

                        Mietvertrag-1:
                            Mietvertrag: "Mieteinheit-1.Mietvertrag-1"
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->seed(TestDataSeeder::class);
    }
}
