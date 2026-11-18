<?php

namespace Tests\Unit;

use App\Support\IbanFormatter;
use Tests\TestCase;

class IbanFormatterTest extends TestCase
{
    public function test_it_groups_an_unspaced_iban_into_4_character_blocks(): void
    {
        $this->assertSame('DE46 8505 0100 0232 0674 14', IbanFormatter::format('DE46850501000232067414'));
    }

    public function test_it_normalizes_an_already_spaced_iban(): void
    {
        $this->assertSame('DE46 8505 0100 0232 0674 14', IbanFormatter::format('DE46 8505 0100 0232 0674 14'));
    }

    public function test_it_uppercases_a_lowercase_iban(): void
    {
        $this->assertSame('DE46 8505 0100 0232 0674 14', IbanFormatter::format('de46850501000232067414'));
    }

    public function test_null_and_empty_values_are_passed_through_unchanged(): void
    {
        $this->assertNull(IbanFormatter::format(null));
        $this->assertSame('', IbanFormatter::format(''));
    }
}
