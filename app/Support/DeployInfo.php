<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Which commit this copy of the app was built from, read from the two marker
 * files scripts/build-deploy-zip.sh writes into deploy.zip. Anything missing
 * or not shaped right is "unknown". Nothing else is ever read or returned.
 */
class DeployInfo
{
    public const UNKNOWN = 'unknown';

    /** @return array{commit_short: string, commit: string, built_at: string} */
    public static function read(?string $directory = null): array
    {
        $directory ??= base_path();

        $commit = self::file($directory.'/.deploy-zip-commit');
        $commit = preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? $commit : self::UNKNOWN;

        $time = self::file($directory.'/.deploy-zip-time');
        try {
            $time = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $time) === 1
                ? CarbonImmutable::parse($time)->utc()->format('Y-m-d\TH:i:s\Z')
                : self::UNKNOWN;
        } catch (\Throwable) {
            $time = self::UNKNOWN;
        }

        return [
            'commit_short' => $commit === self::UNKNOWN ? self::UNKNOWN : substr($commit, 0, 7),
            'commit' => $commit,
            'built_at' => $time,
        ];
    }

    private static function file(string $path): string
    {
        $text = is_file($path) ? @file_get_contents($path, false, null, 0, 100) : false;

        return $text === false ? '' : trim($text);
    }
}
