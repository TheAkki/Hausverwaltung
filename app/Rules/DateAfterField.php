<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Drop-in replacement for Laravel's built-in "after:field" rule for comparing two
 * date fields (e.g. end_date after start_date).
 *
 * Laravel's "after:field" rule resolves the comparison via Carbon::parse(), which
 * first tries to parse the literal field NAME (e.g. "start_date") as a date before
 * falling back to reading its value. On this PHP/Carbon version, that failed parse
 * attempt throws a fatal Error (not an Exception) while PHP tries to populate the
 * DateMalformedStringException with a legacy dynamic property, which Laravel's own
 * `catch (Exception)` around it cannot catch — crashing the whole request with a 500
 * instead of failing validation, every time the compared field is actually filled in.
 * This rule compares using strtotime() instead, which never throws.
 */
class DateAfterField implements ValidationRule
{
    public function __construct(
        private readonly ?string $afterValue,
        private readonly string $message,
        private readonly bool $orEqual = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->afterValue === null || $this->afterValue === '' || $value === null || $value === '') {
            return;
        }

        $after = strtotime($this->afterValue);
        $current = strtotime((string) $value);

        if ($after === false || $current === false) {
            return;
        }

        $fails = $this->orEqual ? $current < $after : $current <= $after;

        if ($fails) {
            $fail($this->message);
        }
    }
}
