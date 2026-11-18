<?php

namespace Database\Seeders;

use App\Models\CostCategory;
use Illuminate\Database\Seeder;

/**
 * Seeds the standard set of German Betriebskosten (operating cost) categories.
 */
class CostCategorySeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    public const CATEGORIES = [
        'Öffentliche Lasten',
        'Wasserversorgung',
        'Entwässerung',
        'Heizkosten',
        'Warmwasser',
        'Wärmekosten',
        'Personen- oder Lastaufzug',
        'Straßenreinigung & Müllbeseitigung',
        'Gebäudereinigung & Ungezieferbekämpfung',
        'Gartenpflege',
        'Beleuchtung',
        'Schornsteinreinigung',
        'Sach- und Haftpflichtversicherung',
        'Hauswart',
        'Gemeinschaftsantenne, Breitbandnetz, Glasfaser',
        'Wäschepflege',
        'Sonstige Betriebskosten',
    ];

    /**
     * Categories that exist for cost tracking (e.g. loan repayments) but are not
     * passed on to tenants via the Betriebskostenabrechnung.
     *
     * @var array<int, string>
     */
    public const NON_BILLABLE_CATEGORIES = [
        'Zinsen',
        'Tilgung',
    ];

    /**
     * Categories that are not tax relevant, e.g. the repayment share of a loan
     * (unlike its interest share, it is no deductible expense).
     *
     * @var array<int, string>
     */
    public const NON_TAX_RELEVANT_CATEGORIES = [
        'Tilgung',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $name) {
            $this->seedCategory($name, true);
        }

        foreach (self::NON_BILLABLE_CATEGORIES as $name) {
            $this->seedCategory($name, false);
        }
    }

    /**
     * Creates the category unless it already exists; an existing one keeps its flags.
     */
    private function seedCategory(string $name, bool $isRelevantForBilling): void
    {
        CostCategory::firstOrCreate(
            ['name' => $name],
            [
                'is_relevant_for_billing' => $isRelevantForBilling,
                'is_tax_relevant' => ! in_array($name, self::NON_TAX_RELEVANT_CATEGORIES, true),
            ]
        );
    }
}
