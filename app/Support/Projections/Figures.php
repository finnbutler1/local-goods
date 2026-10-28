<?php

namespace App\Support\Projections;

/**
 * How projection figures are written on the pages, CSV and docs (ADR 0030):
 * rounded, because they are projections, not results.
 */
final class Figures
{
    /** "$612", "$28k", "$1.83M", "−$198k"; "n/a" when there is nothing to show. */
    public static function dollars(?float $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        $sign = $value < -0.5 ? '−' : '';
        $abs = abs($value);

        return $sign.match (true) {
            round($abs) >= 999_500 => '$'.number_format($abs / 1_000_000, 2).'M',
            round($abs) >= 1_000 => '$'.number_format($abs / 1_000).'k',
            default => '$'.number_format($abs),
        };
    }

    /** "$6.64", "−$1.33"; whole dollars stay whole ("$50"). */
    public static function exact(?float $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        $abs = abs($value);
        $text = '$'.(fmod(round($abs, 2), 1.0) === 0.0 ? number_format($abs) : number_format($abs, 2));

        return ($value < -0.005 ? '−' : '').$text;
    }

    /** "1,200", "74,900". */
    public static function count(?float $value): string
    {
        return $value === null ? 'n/a' : number_format(round($value));
    }

    /** "85%", "1.5%", "0.25%". */
    public static function percent(?float $share): string
    {
        return $share === null ? 'n/a' : rtrim(rtrim(number_format(100 * $share, 2), '0'), '.').'%';
    }

    /** One assumption's value for a case, written by its unit. @param array<string, mixed> $assumption */
    public static function assumption(array $assumption, string $case): string
    {
        $value = (float) $assumption[$case];

        return match (true) {
            str_starts_with($assumption['unit'], 'share') => self::percent($value),
            str_starts_with($assumption['unit'], 'dollars') => self::exact($value),
            default => rtrim(rtrim(number_format($value, 2), '0'), '.'),
        };
    }
}
