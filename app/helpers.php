<?php

if (! function_exists('euro')) {
    function euro(float|int|string|null $amount, int $decimals = 2): string
    {
        return number_format((float) $amount, $decimals, ',', '.');
    }
}
