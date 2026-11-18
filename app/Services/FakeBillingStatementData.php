<?php

namespace App\Services;

use App\Models\BillingInterval;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\SettlementClaim;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Builds fake (in-memory, never persisted) data matching the exact shape
 * BillingStatementController::render() passes to a real Betriebskostenabrechnung
 * template, so a template's Blade content can be previewed without first needing a
 * real Verwaltungsgruppe/-einheit/Abrechnung with data.
 */
class FakeBillingStatementData
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $managementGroup = new ManagementGroup(['description' => 'Musterverwaltung GbR']);
        $managementUnit = new ManagementUnit([
            'street' => 'Musterstraße 1', 'postal_code' => '12345', 'city' => 'Musterstadt', 'description' => 'Beispielobjekt',
        ]);
        $billingInterval = new BillingInterval([
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'description' => 'Abrechnungsjahr 2026',
        ]);
        $landlord = new Landlord([
            'salutation' => 'Herr', 'first_name' => 'Erwin', 'name' => 'Eigentümer', 'company_name' => 'Musterverwaltung GbR',
            'street' => 'Verwalterweg 1', 'postal_code' => '12345', 'city' => 'Musterstadt',
            'email' => 'kontakt@musterverwaltung.example', 'phone' => '01234 567890',
            'iban' => 'DE00 0000 0000 0000 0000 00', 'bic' => 'MUSTERBIC',
        ]);

        $tenant = new Tenant([
            'salutation' => 'Herr', 'first_name' => 'Max', 'last_name' => 'Mustermann',
            'street' => 'Beispielweg 2', 'postal_code' => '54321', 'city' => 'Musterhausen',
        ]);
        $rentalUnit = new RentalUnit(['type' => 'Wohnung', 'size' => 65.5, 'description' => 'Erdgeschoss links']);
        $lease = new Lease(['cold_rent' => 550, 'rent_adjustment_type' => 'fixed']);

        $otherRentalUnit = new RentalUnit(['type' => 'Garage', 'size' => 12.0, 'description' => 'Garage 3']);

        $leases = collect([
            [
                'lease' => $lease,
                'tenants' => collect([$tenant]),
                'tenantNames' => 'Max Mustermann',
                'rentalUnit' => $rentalUnit,
                'periodStart' => Carbon::parse('2026-01-01'),
                'periodEnd' => Carbon::parse('2026-12-31'),
                'days' => 365,
                'currentColdRent' => 550.0,
                'currentUtilityAdvance' => 120.0,
                'monthlyUtilityAdvances' => collect([
                    '2026-01' => ['label' => 'Januar 2026', 'amount' => 120.0],
                    '2026-02' => ['label' => 'Februar 2026', 'amount' => 120.0],
                ]),
                'totalUtilityAdvance' => 1440.0,
                'otherRents' => collect([
                    ['rentalUnit' => $otherRentalUnit, 'coldRent' => 45.0, 'utilityAdvance' => 0.0],
                ]),
                'allocatedCosts' => [
                    'byCategory' => collect([
                        'Heizkosten' => 504.0,
                        'Wasserversorgung' => 126.0,
                        'Gebäudereinigung & Ungezieferbekämpfung' => 108.0,
                    ]),
                    'byStage' => [
                        'managementGroup' => [
                            'byCategory' => collect(['Heizkosten' => 300.0]),
                            'categoryDetails' => collect([
                                'Heizkosten' => ['sum' => 600.0, 'key' => 'Fläche', 'share' => 300.0],
                            ]),
                            'total' => 300.0,
                        ],
                        'managementUnit' => [
                            'byCategory' => collect(['Heizkosten' => 204.0, 'Wasserversorgung' => 96.0]),
                            'categoryDetails' => collect([
                                'Heizkosten' => ['sum' => 340.0, 'key' => 'Fläche', 'share' => 204.0],
                                'Wasserversorgung' => ['sum' => 160.0, 'key' => 'Fläche', 'share' => 96.0],
                            ]),
                            'total' => 300.0,
                        ],
                        'rentalUnit' => [
                            'byCategory' => collect(['Wasserversorgung' => 30.0, 'Gebäudereinigung & Ungezieferbekämpfung' => 90.0]),
                            'categoryDetails' => collect([
                                'Wasserversorgung' => ['sum' => 30.0, 'key' => 'Mieteinheit', 'share' => 30.0],
                                'Gebäudereinigung & Ungezieferbekämpfung' => ['sum' => 90.0, 'key' => 'Fläche', 'share' => 90.0],
                            ]),
                            'total' => 120.0,
                        ],
                        'lease' => [
                            'byCategory' => collect(['Gebäudereinigung & Ungezieferbekämpfung' => 18.0]),
                            'categoryDetails' => collect([
                                'Gebäudereinigung & Ungezieferbekämpfung' => ['sum' => 18.0, 'key' => 'Individuell', 'share' => 18.0],
                            ]),
                            'total' => 18.0,
                        ],
                    ],
                    'total' => 738.0,
                ],
                // Nachzahlung (positiv) bzw. Rückerstattung (negativ) gegenüber den
                // bereits geleisteten Betriebskostenvorauszahlungen dieses Mietvertrags.
                'balance' => 738.0 - 1440.0,
                // (738.0 / 12) / 65.5 = 0.9389... €/m² pro Monat, aufgerundet auf 1,00 €/m²,
                // mal 65.5 m² = 65.5 € – siehe BillingCostAllocationService::suggestedMonthlyUtilityAdvance().
                'suggestedUtilityAdvance' => 65.5,
            ],
            // Konzept.md "##### Leerstände": eine leerstehende, abrechnungsrelevante
            // Mieteinheit wird "wie ein Mieter gehandhabt" und taucht mit einem eigenen
            // Eintrag in $leases auf – siehe BillingCostAllocationService::
            // vacancyLeaseEntries(). "isVacancy" unterscheidet sie von einem echten
            // Mietvertrag, damit ein Template kein Anschreiben an "Leerstand" adressiert.
            [
                'lease' => new Lease(['rental_unit_id' => null, 'start_date' => '2026-07-01', 'end_date' => '2026-09-30']),
                'tenants' => collect(),
                'tenantNames' => 'Leerstand',
                'rentalUnit' => new RentalUnit(['type' => 'Wohnung', 'size' => 40.0, 'description' => 'Dachgeschoss']),
                'periodStart' => Carbon::parse('2026-07-01'),
                'periodEnd' => Carbon::parse('2026-09-30'),
                'days' => 92,
                'currentColdRent' => 0.0,
                'currentUtilityAdvance' => 0.0,
                'monthlyUtilityAdvances' => collect(),
                'totalUtilityAdvance' => 0.0,
                'otherRents' => collect(),
                'isVacancy' => true,
                'claims' => collect([
                    new SettlementClaim(['date' => '2026-10-01', 'amount' => 45.0, 'notes' => 'Nachforderung Abrechnungsgesellschaft']),
                ]),
                'allocatedCosts' => [
                    'byCategory' => collect(['Heizkosten' => 30.0]),
                    'byStage' => [
                        'managementGroup' => ['byCategory' => collect(), 'categoryDetails' => collect(), 'total' => 0.0],
                        'managementUnit' => ['byCategory' => collect(['Heizkosten' => 30.0]), 'categoryDetails' => collect([
                            'Heizkosten' => ['sum' => 340.0, 'key' => 'Fläche', 'share' => 30.0],
                        ]), 'total' => 30.0],
                        'rentalUnit' => ['byCategory' => collect(), 'categoryDetails' => collect(), 'total' => 0.0],
                        'lease' => ['byCategory' => collect(), 'categoryDetails' => collect(), 'total' => 0.0],
                    ],
                    'total' => 30.0,
                ],
                'balance' => 30.0,
                'suggestedUtilityAdvance' => 0.0,
            ],
        ]);

        $costsByCategory = collect([
            'Heizkosten' => 840.0,
            'Wasserversorgung' => 210.0,
            'Gebäudereinigung & Ungezieferbekämpfung' => 180.0,
        ]);

        $costsByMonth = collect([
            '2026-01' => ['label' => 'Januar 2026', 'amount' => 105.0],
            '2026-02' => ['label' => 'Februar 2026', 'amount' => 105.0],
        ]);

        // Konzept.md "#### Template": "alle relevanten Buchungen ... gruppiert nach
        // Zuordnungsebene und danach nach Kategorie" – dieselbe Form wie
        // BillingStatementController::bookingsByAssignmentLevel(): Ebene -> Kategorie ->
        // Liste von Buchungen mit Datum, Empfänger, Beschreibung, Betrag.
        $bookingsByLevel = collect([
            'Verwaltungseinheit' => collect([
                'Beleuchtung' => collect([
                    ['date' => Carbon::parse('2026-01-05'), 'issuer' => 'Stadtwerke Musterstadt', 'description' => 'Strom Haus', 'amount' => 61.0],
                    ['date' => Carbon::parse('2026-02-05'), 'issuer' => 'Stadtwerke Musterstadt', 'description' => 'Strom Haus', 'amount' => 61.0],
                ]),
                'Sach- und Haftpflichtversicherung' => collect([
                    ['date' => Carbon::parse('2026-01-15'), 'issuer' => 'Musterversicherung AG', 'description' => 'Jahresprämie', 'amount' => 620.0],
                ]),
            ]),
            'Mieteinheit' => collect([
                'Gebäudereinigung & Ungezieferbekämpfung' => collect([
                    ['date' => Carbon::parse('2026-03-10'), 'issuer' => 'Reinigung Muster GmbH', 'description' => 'Treppenhausreinigung', 'amount' => 90.0],
                ]),
            ]),
        ]);

        return [
            'managementGroup' => $managementGroup,
            'managementUnit' => $managementUnit,
            'billingInterval' => $billingInterval,
            'landlord' => $landlord,
            'leases' => $leases,
            'costsByCategory' => $costsByCategory,
            'costsByMonth' => $costsByMonth,
            'headerImage' => null,
            'managementUnitTotalArea' => 65.5 + 12.0,
            'managementGroupTotalArea' => 340.0,
            'managementUnitRelevantUnits' => 2,
            'managementGroupRelevantUnits' => 5,
            'managementUnitPersonMonths' => 24,
            'managementGroupPersonMonths' => 96,
            'billingIntervalDays' => 365,
            'billingIntervalMonths' => 12,
            'bookingsByLevel' => $bookingsByLevel,
        ];
    }
}
