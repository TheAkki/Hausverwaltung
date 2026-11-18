<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Der Beleg einer Rechnung steht auf der Detailseite deutlich sichtbar mit "Öffnen"
 * (neuer Tab) und "Herunterladen" (Original-Dateiname); ohne Beleg ein Hinweis mit
 * Link zum Hochladen.
 */
class InvoiceReceiptDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function invoice(?string $path, ?string $originalName = null): Invoice
    {
        $group = $this->user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Versicherung', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        return Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementGroup->getMorphClass(),
            'costable_id' => $managementGroup->id,
            'invoice_date' => '2026-01-01',
            'amount' => 100,
            'periodicity' => 'once',
            'file_path' => $path,
            'file_original_name' => $originalName,
        ]);
    }

    // KI-generiert
    public function test_an_attached_receipt_can_be_opened_and_downloaded(): void
    {
        $invoice = $this->invoice('invoices/abc.pdf', 'Rechnung Concordia.pdf');
        $url = Storage::url('invoices/abc.pdf');

        $this->actingAs($this->user)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('data-receipt', false)
            ->assertSee('Rechnung Concordia.pdf')
            ->assertSee('href="'.$url.'" target="_blank" rel="noopener"', false)
            ->assertSee('href="'.$url.'" download="Rechnung Concordia.pdf"', false)
            ->assertSee('Öffnen')
            ->assertSee('Herunterladen')
            ->assertDontSee('Kein Beleg hinterlegt.');
    }

    // KI-generiert
    public function test_without_original_name_the_stored_file_name_is_shown(): void
    {
        $invoice = $this->invoice('invoices/abc.pdf');

        $this->actingAs($this->user)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('abc.pdf');
    }

    // KI-generiert
    public function test_without_receipt_a_hint_links_to_the_upload(): void
    {
        $invoice = $this->invoice(null);

        $this->actingAs($this->user)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Kein Beleg hinterlegt.')
            ->assertSee('href="'.route('invoices.edit', $invoice).'"', false)
            ->assertDontSee('Herunterladen');
    }

    // KI-generiert
    public function test_the_receipt_box_is_translated(): void
    {
        $invoice = $this->invoice('invoices/abc.pdf', 'beleg.pdf');

        $this->actingAs($this->user)->withSession(['locale' => 'en'])->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Receipt')
            ->assertSee('Download');
    }
}
