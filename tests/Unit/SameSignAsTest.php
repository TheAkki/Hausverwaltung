<?php

namespace Tests\Unit;

use App\Rules\SameSignAs;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// KI-generiert
class SameSignAsTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: bool}>
     */
    public static function cases(): array
    {
        return [
            'positiv/positiv' => [100, 80, true],
            'positiv/null' => [100, 0, true],
            'positiv/negativ' => [100, -1, false],
            'negativ/negativ' => [-100, -80, true],
            'negativ/null' => [-100, 0, true],
            'negativ/positiv' => [-100, 1, false],
            'null/negativ' => [0, -1, false],
            'Referenz fehlt' => [null, -1, true],
            'Referenz kein Betrag' => ['abc', -1, true],
            'Wert kein Betrag' => [-100, 'abc', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_the_value_must_share_the_sign_of_the_reference(mixed $reference, mixed $value, bool $passes): void
    {
        $validator = Validator::make(['amount' => $value], ['amount' => [new SameSignAs($reference)]]);

        $this->assertSame($passes, $validator->passes());
    }
}
