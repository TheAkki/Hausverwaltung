<?php

namespace App\Services\InvoiceSeries;

/**
 * Ausgleichsrechnung für die Prognose schwankender Beträge (Prognosemodus
 * "Regression"): lineare, quadratische und – bei nur positiven Beträgen –
 * exponentielle Ausgleichsfunktion; gewählt wird die mit dem höchsten
 * Bestimmtheitsmaß R². Rein rechnerisch, ohne Datenbankzugriff.
 */
final class CurveFit
{
    /**
     * Fits every regression candidate that $points supports (linear always; quadratic
     * needs >= 3 points to be non-degenerate; exponential needs every amount to be
     * positive, since it's fit via ln(y)), scores each by its coefficient of
     * determination (R², all computed against the same original, non-transformed
     * amounts, so the three are directly comparable), and returns whichever predictor
     * scored highest. Ties (including "only one candidate could be fit at all") favor
     * the earlier, simpler candidate in the [linear, quadratic, exponential] order,
     * since a tied/near-tied fit gives no evidence the extra complexity is warranted.
     *
     * @param  array<int, array{0: int, 1: float}>  $points
     * @return (callable(int $x): float)|null
     */
    public static function bestFit(array $points): ?callable
    {
        $candidates = array_filter([
            self::linearRegression($points),
            count($points) >= 3 ? self::quadraticRegression($points) : null,
            collect($points)->every(fn (array $p) => $p[1] > 0) ? self::exponentialRegression($points) : null,
        ]);

        $bestPredict = null;
        $bestR2 = -INF;

        foreach ($candidates as [$predict, $r2]) {
            if ($r2 > $bestR2) {
                $bestR2 = $r2;
                $bestPredict = $predict;
            }
        }

        return $bestPredict;
    }

    /**
     * The proportion of $points' variance the given $predict function explains (1.0 =
     * perfect fit). If every point shares the exact same y (no variance to explain at
     * all), any fit passing through them is trivially perfect.
     *
     * @param  array<int, array{0: int, 1: float}>  $points
     * @param  callable(int $x): float  $predict
     */
    private static function coefficientOfDetermination(array $points, callable $predict): float
    {
        $meanY = array_sum(array_column($points, 1)) / count($points);
        $ssTotal = array_sum(array_map(fn (array $p) => ($p[1] - $meanY) ** 2, $points));

        if ($ssTotal == 0.0) {
            return 1.0;
        }

        $ssResidual = array_sum(array_map(fn (array $p) => ($p[1] - $predict($p[0])) ** 2, $points));

        return 1 - $ssResidual / $ssTotal;
    }

    /**
     * Ordinary least squares: y = a + b*x.
     *
     * @param  array<int, array{0: int, 1: float}>  $points
     * @return array{0: callable(int $x): float, 1: float}|null [predictor, R²], or null
     *                                                          if degenerate (every
     *                                                          point shares the same x
     *                                                          – cannot happen via the
     *                                                          normal caller, since
     *                                                          occurrence indices are
     *                                                          always distinct, but
     *                                                          guarded regardless)
     */
    private static function linearRegression(array $points): ?array
    {
        $n = count($points);
        $sumX = array_sum(array_column($points, 0));
        $sumY = array_sum(array_column($points, 1));
        $sumXY = array_sum(array_map(fn (array $p) => $p[0] * $p[1], $points));
        $sumXX = array_sum(array_map(fn (array $p) => $p[0] ** 2, $points));

        $denominator = $n * $sumXX - $sumX ** 2;

        if ($denominator == 0.0) {
            return null;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;
        $intercept = ($sumY - $slope * $sumX) / $n;
        $predict = fn (int $x): float => $intercept + $slope * $x;

        return [$predict, self::coefficientOfDetermination($points, $predict)];
    }

    /**
     * Ordinary least squares: y = a + b*x + c*x².
     *
     * @param  array<int, array{0: int, 1: float}>  $points
     * @return array{0: callable(int $x): float, 1: float}|null [predictor, R²], or null
     *                                                          if degenerate (fewer
     *                                                          than 3 distinct x values
     *                                                          among $points)
     */
    private static function quadraticRegression(array $points): ?array
    {
        $sumX = $sumX2 = $sumX3 = $sumX4 = $sumY = $sumXY = $sumX2Y = 0.0;

        foreach ($points as [$x, $y]) {
            $sumX += $x;
            $sumX2 += $x ** 2;
            $sumX3 += $x ** 3;
            $sumX4 += $x ** 4;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2Y += $x ** 2 * $y;
        }

        $n = count($points);
        $coefficients = self::solve3x3(
            [[$n, $sumX, $sumX2], [$sumX, $sumX2, $sumX3], [$sumX2, $sumX3, $sumX4]],
            [$sumY, $sumXY, $sumX2Y]
        );

        if ($coefficients === null) {
            return null;
        }

        [$a, $b, $c] = $coefficients;
        $predict = fn (int $x): float => $a + $b * $x + $c * $x ** 2;

        return [$predict, self::coefficientOfDetermination($points, $predict)];
    }

    /**
     * y = a·e^(b·x), fit by linearizing to ln(y) = ln(a) + b·x and running an ordinary
     * linearRegression() on that – only called when every $points amount is positive
     * (bestFittingCurve()), since ln() of a zero/negative amount is undefined. R² is
     * still computed against the original (non-logarithmic) amounts, so it is directly
     * comparable to the linear/quadratic candidates' own R².
     *
     * @param  array<int, array{0: int, 1: float}>  $points
     * @return array{0: callable(int $x): float, 1: float}|null [predictor, R²]
     */
    private static function exponentialRegression(array $points): ?array
    {
        $linearized = array_map(fn (array $p) => [$p[0], log($p[1])], $points);
        $fit = self::linearRegression($linearized);

        if ($fit === null) {
            return null;
        }

        $predict = fn (int $x): float => exp($fit[0]($x));

        return [$predict, self::coefficientOfDetermination($points, $predict)];
    }

    /**
     * Cramer's rule for a 3x3 linear system – solves quadraticRegression()'s normal
     * equations.
     *
     * @param  array<int, array<int, float>>  $matrix  3x3
     * @param  array<int, float>  $vector  length 3
     * @return array<int, float>|null length 3, or null if $matrix is singular
     */
    private static function solve3x3(array $matrix, array $vector): ?array
    {
        $determinant = fn (array $m): float => $m[0][0] * ($m[1][1] * $m[2][2] - $m[1][2] * $m[2][1])
            - $m[0][1] * ($m[1][0] * $m[2][2] - $m[1][2] * $m[2][0])
            + $m[0][2] * ($m[1][0] * $m[2][1] - $m[1][1] * $m[2][0]);

        $d = $determinant($matrix);

        if ($d == 0.0) {
            return null;
        }

        $solution = [];
        for ($col = 0; $col < 3; $col++) {
            $replaced = $matrix;
            for ($row = 0; $row < 3; $row++) {
                $replaced[$row][$col] = $vector[$row];
            }
            $solution[$col] = $determinant($replaced) / $d;
        }

        return $solution;
    }
}
