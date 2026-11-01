<?php

namespace App\Support;

/** Masks personal details before tool arguments are written to the call log. */
class Mask
{
    private const SENSITIVE = ['email', 'phone', 'query', 'first_name', 'last_name', 'notes', 'reason', 'address', 'city', 'zip_code'];

    public static function arguments(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                $arguments[$key] = self::arguments($value);
            } elseif (is_string($value) && in_array($key, self::SENSITIVE, true)) {
                $arguments[$key] = self::string($value);
            }
        }

        return $arguments;
    }

    /** Masks every email address inside free text, such as an error message that quotes one. */
    public static function text(string $text): string
    {
        return preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            fn (array $m) => self::string($m[0]),
            $text,
        ) ?? $text;
    }

    public static function string(string $value): string
    {
        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        $length = mb_strlen($value);

        return $length <= 2 ? str_repeat('*', $length) : mb_substr($value, 0, 1).str_repeat('*', min($length - 2, 6)).mb_substr($value, -1);
    }
}
