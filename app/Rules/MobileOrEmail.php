<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * Philippine mobile number (09XX XXX XXXX, +639XX..., 639XX...) or an email
 * address whose domain really receives mail (DNS MX/A check).
 */
class MobileOrEmail implements ValidationRule
{
    /** @return array{0:string,1:string}|null [type, normalized value] */
    public static function normalize(string $value): ?array
    {
        $value = trim($value);

        if (str_contains($value, '@')) {
            return ['email', mb_strtolower($value)];
        }

        $digits = preg_replace('/[\s\-().]/', '', $value);
        if (preg_match('/^(?:\+?63|0)(9\d{9})$/', $digits, $m) && ! preg_match('/^9(\d)\1{8}$/', $m[1])) {
            return ['phone', '+63'.$m[1]];
        }

        return null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = trim((string) $value);

        if (str_contains($value, '@')) {
            if (Validator::make(['email' => $value], ['email' => 'email:rfc,dns|max:254'])->fails()) {
                $fail('Enter a valid email address.');
            }

            return;
        }

        if (self::normalize($value) === null) {
            $fail('Enter a valid mobile number, like 0917 123 4567, or an email address.');
        }
    }
}
