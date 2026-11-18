<?php

namespace Tests\Unit;

use App\Rules\ValidIban;
use Tests\TestCase;

class ValidIbanTest extends TestCase
{
    private function failureFor(string $iban): ?string
    {
        $failed = null;
        (new ValidIban)->validate('iban', $iban, function ($message) use (&$failed) {
            $failed = $message;
        });

        return $failed;
    }

    public function test_valid_ibans_pass(): void
    {
        $this->assertNull($this->failureFor('DE89370400440532013000'));
        $this->assertNull($this->failureFor('GB29NWBK60161331926819'));
        $this->assertNull($this->failureFor('FR1420041010050500013M02606'));
    }

    public function test_valid_iban_with_spaces_passes(): void
    {
        $this->assertNull($this->failureFor('DE89 3704 0044 0532 0130 00'));
    }

    public function test_wrong_checksum_fails(): void
    {
        $this->assertNotNull($this->failureFor('DE89370400440532013001'));
    }

    public function test_malformed_value_fails(): void
    {
        $this->assertNotNull($this->failureFor('not an iban'));
        $this->assertNotNull($this->failureFor('DE123'));
    }
}
