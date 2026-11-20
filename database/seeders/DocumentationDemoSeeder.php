<?php

namespace Database\Seeders;

use App\Models\BillingInterval;
use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\SettlementClaim;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Frei erfundene Beispieldaten für die Screenshots der Bedienungsanleitung
 * (docs:screenshots) – dieselben Namen wie im Beispiel des Importskripts. Läuft nur auf
 * der eigenen Datenbank des Befehls, nie auf echten Daten.
 */
class DocumentationDemoSeeder extends Seeder
{
    public const EMAIL = 'anna.beispiel@example.com';

    public const SHARED_GROUP = 'Erbengemeinschaft Beispiel';

    public const MANAGEMENT_GROUP = 'Musterstraße 1';

    private const STREET = 'Musterstraße 1';

    private const POSTAL_CODE = '01234';

    private const CITY = 'Musterstadt';

    public function run(): void
    {
        $this->call(CostCategorySeeder::class);
        $this->call(BillingTemplateSeeder::class);

        $user = User::create(['name' => 'Anna Beispiel', 'email' => self::EMAIL, 'password' => Str::password()]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $group = Group::create(['name' => self::SHARED_GROUP, 'owner_id' => $user->id, 'description' => 'Gemeinsam vermietetes Haus']);
        $group->users()->attach($user->id, ['role' => 'admin']);
        Landlord::create(['user_group_id' => $group->id, 'name' => self::SHARED_GROUP, 'street' => 'Lindenweg 2', 'postal_code' => self::POSTAL_CODE, 'city' => self::CITY, 'iban' => 'DE02120300000000202051']);

        $managementGroup = ManagementGroup::create(['description' => self::MANAGEMENT_GROUP, 'user_group_id' => $group->id]);
        $previousYear = now()->subYear()->year;
        BillingInterval::create(['management_group_id' => $managementGroup->id, 'start_date' => "{$previousYear}-01-01", 'end_date' => "{$previousYear}-12-31", 'description' => 'Erstes Jahr']);
        $unit = ManagementUnit::create(['management_group_id' => $managementGroup->id, 'street' => self::STREET, 'postal_code' => self::POSTAL_CODE, 'city' => self::CITY]);

        $this->seedLeases($group, $unit, $previousYear);
        $this->seedInvoices($group, $managementGroup, $unit);
    }

    private function seedLeases(Group $group, ManagementUnit $unit, int $previousYear): void
    {
        $ground = RentalUnit::create(['management_unit_id' => $unit->id, 'type' => 'Wohnung', 'description' => 'EG links', 'size' => 62.5, 'floor' => 0, 'is_relevant_for_billing' => true]);
        $upper = RentalUnit::create(['management_unit_id' => $unit->id, 'type' => 'Wohnung', 'description' => 'OG rechts', 'size' => 71, 'floor' => 1, 'is_relevant_for_billing' => true]);
        $parking = RentalUnit::create(['management_unit_id' => $unit->id, 'type' => 'Stellplatz', 'description' => 'Stellplatz 1', 'is_relevant_for_billing' => false]);

        $meier = $this->tenant($group, 'Frau', 'Maria', 'Meier');
        $schulz = $this->tenant($group, 'Herr', 'Stefan', 'Schulz');

        $groundLease = $this->lease($ground, "{$previousYear}-01-01", 500, 120, 2, $meier);
        $this->lease($upper, "{$previousYear}-04-01", 610, 110, 1, $schulz);
        Lease::create(['rental_unit_id' => $parking->id, 'coupled_to_lease_id' => $groundLease->id, 'start_date' => "{$previousYear}-01-01", 'rent_adjustment_type' => 'fixed', 'cold_rent' => 30]);

        SettlementClaim::create([
            'lease_id' => $groundLease->id, 'billing_interval_id' => BillingInterval::where('description', 'Erstes Jahr')->value('id'),
            'amount' => 84.20, 'date' => now()->startOfMonth()->toDateString(), 'notes' => "Abrechnung {$previousYear}",
        ]);
    }

    private function seedInvoices(Group $group, ManagementGroup $managementGroup, ManagementUnit $unit): void
    {
        $utilities = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke Musterstadt', 'company_name' => 'Stadtwerke Musterstadt GmbH', 'street' => 'Werkstraße 5', 'postal_code' => self::POSTAL_CODE, 'city' => self::CITY]);
        $utilities->accounts()->create(['customer_number' => 'W-4711', 'description' => 'Wasser', 'direct_debit' => true]);
        $caretaker = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Hausmeisterdienst Muster', 'street' => 'Gartenweg 3', 'postal_code' => self::POSTAL_CODE, 'city' => self::CITY]);
        $interval = $managementGroup->billingIntervals()->whereYear('start_date', now()->year)->sole();
        $previous = $managementGroup->billingIntervals()->whereYear('start_date', now()->subYear()->year)->sole();
        $month = now()->startOfMonth();
        $lastYear = now()->subYear();

        // Kosten des Vorjahres – Grundlage der Betriebskostenabrechnung im Screenshot.
        $this->invoice($utilities, $unit, $previous, $lastYear->copy()->setDate($lastYear->year, 12, 15), 860, 'Wasser Jahresabrechnung', [['Wasserversorgung', 560], ['Entwässerung', 300]], booked: true);
        $this->invoice($caretaker, $unit, $previous, $lastYear->copy()->setDate($lastYear->year, 6, 1), 640, 'Treppenhausreinigung', [['Gebäudereinigung & Ungezieferbekämpfung', 640]], booked: true);
        $this->invoice($caretaker, $unit, $previous, $lastYear->copy()->setDate($lastYear->year, 11, 20), 210, 'Gartenpflege Herbst', [['Gartenpflege', 210]], booked: true);

        $this->invoice($utilities, $unit, $interval, $month->copy()->subMonths(2), 45, 'Wasserabschlag', [['Wasserversorgung', 30], ['Entwässerung', 15]], booked: true);
        $this->invoice($utilities, $unit, $interval, $month->copy()->subMonth(), 45, 'Wasserabschlag', [['Wasserversorgung', 30], ['Entwässerung', 15]], booked: false);
        // Nur teilweise auf Kategorien verteilt – erscheint unter "Nicht zugeordnete Buchungen".
        $this->invoice($caretaker, $managementGroup, $interval, $month->copy()->subMonth(), 180, 'Hausmeister Winterdienst', [['Hauswart', 120]], booked: true);
    }

    private function tenant(Group $group, string $salutation, string $firstName, string $lastName): Tenant
    {
        return Tenant::create(['user_group_id' => $group->id, 'salutation' => $salutation, 'first_name' => $firstName, 'last_name' => $lastName, 'street' => self::STREET, 'postal_code' => self::POSTAL_CODE, 'city' => self::CITY]);
    }

    private function lease(RentalUnit $rentalUnit, string $start, float $rent, float $utilities, int $occupants, Tenant $tenant): Lease
    {
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => $start, 'rent_adjustment_type' => 'fixed', 'cold_rent' => $rent]);
        $lease->leaseIntervals()->first()->update(['utility_advance_payment' => $utilities, 'occupant_count' => $occupants]);
        $lease->tenants()->attach($tenant->id);

        return $lease;
    }

    /**
     * @param  list<array{0: string, 1: float}>  $allocations  Kategorie, Betrag
     */
    private function invoice(InvoiceIssuer $issuer, ManagementGroup|ManagementUnit $costable, BillingInterval $interval, Carbon $date, float $amount, string $description, array $allocations, bool $booked): void
    {
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'costable_type' => $costable->getMorphClass(), 'costable_id' => $costable->id,
            'amount' => $amount, 'invoice_date' => $date->toDateString(), 'periodicity' => Invoice::PERIODICITY_ONCE, 'description' => $description,
            'booking_date' => $booked ? $date->copy()->addDays(3)->toDateString() : null,
        ]);

        foreach ($allocations as [$category, $share]) {
            $invoice->costs()->create([
                'costable_type' => $costable->getMorphClass(), 'costable_id' => $costable->id, 'billing_interval_id' => $interval->id,
                'cost_category_id' => CostCategory::whereNull('user_group_id')->where('name', $category)->value('id'),
                'amount' => $share, 'period_date' => $date->toDateString(),
                'booking_date' => $booked ? $date->copy()->addDays(3)->toDateString() : null,
            ]);
        }
    }
}
