<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * A real-looking full name: letters only (accents and ñ included), at least
 * a first and last name, no numbers or symbols, and no keyboard mashing.
 * Allowed punctuation is limited to what real names use: "Ma.", "O'Neil",
 * "Santos-Reyes". The same checks run in the browser (portal/form.blade.php).
 */
class PersonName implements ValidationRule
{
    private const SEQUENCES = ['qwertyuiop', 'asdfghjkl', 'zxcvbnm', 'abcdefghijklmnopqrstuvwxyz'];

    /** @param string[] $blocked lowercase words or phrases that are never accepted */
    public function __construct(private array $blocked = [])
    {
    }

    public static function clean(string $value): string
    {
        $value = str_replace(['’', '‘', '`', '´'], "'", $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = self::clean((string) $value);

        if ($name === '') {
            $fail('Enter your full name.');
        } elseif (preg_match('/\d/u', $name)) {
            $fail('Numbers are not allowed in a name.');
        } elseif (! preg_match("/^\p{L}[\p{L}\p{M}]*\.?(?:[ '\-]\p{L}[\p{L}\p{M}]*\.?)*$/u", $name)) {
            $fail('Use letters only. Symbols are not allowed.');
        } elseif (count(array_filter(explode(' ', $name), fn ($w) => mb_strlen(preg_replace("/[.'\-]/u", '', $w)) >= 2)) < 2) {
            $fail('Enter your first and last name.');
        } elseif (self::looksFake($name, $this->blocked)) {
            $fail('Please enter your real name.');
        }
    }

    public static function looksFake(string $name, array $blocked = []): bool
    {
        $plain = strtolower(Str::ascii($name));
        $words = array_values(array_filter(array_map(
            fn ($w) => preg_replace('/[^a-z]/', '', $w),
            preg_split('/[ \-]+/', $plain)
        )));
        $joined = implode('', $words);

        if (preg_match('/([a-z])\1\1/', $joined)) {                 // aaa, kkk
            return true;
        }
        if (preg_match('/[bcdfghjklmnpqrstvwxz]{6,}/', $joined)) {  // random consonants
            return true;
        }
        foreach ($words as $w) {
            if (preg_match('/([a-z]{2,3})\1\1/', $w)) {              // hahaha, jkjkjk
                return true;
            }
            if (strlen($w) >= 4 && ! preg_match('/[aeiouy]/', $w)) { // no vowels at all
                return true;
            }
        }
        foreach (self::SEQUENCES as $row) {                          // asdfg, qwert, abcde
            foreach ([$row, strrev($row)] as $seq) {
                for ($i = 0; $i + 5 <= strlen($seq); $i++) {
                    if (str_contains($joined, substr($seq, $i, 5))) {
                        return true;
                    }
                }
            }
        }

        $phrase = ' '.implode(' ', $words).' ';
        foreach ($blocked as $b) {
            $b = preg_replace('/[^a-z ]/', '', strtolower(Str::ascii($b)));
            if ($b !== '' && str_contains($phrase, ' '.$b.' ')) {
                return true;
            }
        }

        return false;
    }
}
