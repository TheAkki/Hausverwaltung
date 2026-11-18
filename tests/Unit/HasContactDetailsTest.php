<?php

namespace Tests\Unit;

use App\Models\InvoiceIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers HasContactDetails::fullAddress(), shared by Tenant/InvoiceIssuer/Landlord:
 * omits a missing part (and its separator) instead of leaving a stray comma/space.
 */
class HasContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_address_joins_all_parts_when_all_are_present(): void
    {
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'Hauptstraße 1', 'postal_code' => '12345', 'city' => 'Musterstadt']);

        $this->assertSame('Hauptstraße 1, 12345 Musterstadt', $issuer->fullAddress());
    }

    public function test_full_address_omits_a_missing_street_without_a_leading_comma(): void
    {
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => '', 'postal_code' => '12345', 'city' => 'Musterstadt']);

        $this->assertSame('12345 Musterstadt', $issuer->fullAddress());
    }

    public function test_full_address_omits_missing_postal_code_and_city_without_a_trailing_comma(): void
    {
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'Hauptstraße 1', 'postal_code' => '', 'city' => '']);

        $this->assertSame('Hauptstraße 1', $issuer->fullAddress());
    }

    public function test_full_address_is_empty_when_nothing_is_set(): void
    {
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => '', 'postal_code' => '', 'city' => '']);

        $this->assertSame('', $issuer->fullAddress());
    }
}
