<?php

// KI-generiert

namespace Tests\Unit\InvoiceSeries;

use App\Services\InvoiceSeries\CurveFit;
use PHPUnit\Framework\TestCase;

class CurveFitTest extends TestCase
{
    // KI-generiert
    public function test_a_linear_trend_is_extrapolated(): void
    {
        $predict = CurveFit::bestFit([[0, 100.0], [1, 100.5], [2, 101.0]]);

        $this->assertEqualsWithDelta(101.5, $predict(3), 0.0001);
    }

    // KI-generiert
    public function test_a_quadratic_trend_wins_when_it_fits_best(): void
    {
        $predict = CurveFit::bestFit([[0, 0.0], [1, 1.0], [2, 4.0], [3, 9.0], [4, 16.0]]);

        $this->assertEqualsWithDelta(25.0, $predict(5), 0.0001);
    }

    // KI-generiert
    public function test_an_exponential_trend_wins_for_doubling_amounts(): void
    {
        $predict = CurveFit::bestFit([[0, 100.0], [1, 200.0], [2, 400.0], [3, 800.0]]);

        $this->assertEqualsWithDelta(1600.0, $predict(4), 0.01);
    }

    // KI-generiert
    public function test_constant_amounts_and_degenerate_points(): void
    {
        $this->assertEqualsWithDelta(5.0, CurveFit::bestFit([[0, 5.0], [1, 5.0], [2, 5.0]])(7), 0.0001);
        $this->assertNull(CurveFit::bestFit([[1, 5.0], [1, -6.0]]));
        $this->assertNull(CurveFit::bestFit([[1, 5.0], [1, -6.0], [1, -7.0]]));
        $this->assertNull(CurveFit::bestFit([[1, 5.0], [1, 6.0]]));
    }
}
