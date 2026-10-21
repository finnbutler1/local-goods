<?php

namespace App\Services\Eligibility;

/**
 * An evidence link as it is shown or copied into eval outputs: the same
 * page, without the ad-click and search tracking parameters a browser adds
 * (gclid, gbraid, utm_… and so on). Those identify the person who clicked,
 * not the business. The locked label file keeps the links as Finn saved
 * them (changing a row would break the label lock), so they are cleaned
 * here, wherever they leave that file.
 */
class EvidenceUrl
{
    private const TRACKING = '/^(gclid|gbraid|wbraid|dclid|fbclid|msclkid|srsltid|gclsrc|gad_.+|utm_.+)$/i';

    public static function withoutTracking(string $url): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            [$url, $fragment] = [substr($url, 0, $hash), substr($url, $hash)];
        }
        if (($question = strpos($url, '?')) === false) {
            return $url.$fragment;
        }

        $kept = array_filter(
            explode('&', substr($url, $question + 1)),
            fn ($pair) => $pair !== '' && ! preg_match(self::TRACKING, urldecode(explode('=', $pair, 2)[0])),
        );

        return substr($url, 0, $question).($kept === [] ? '' : '?'.implode('&', $kept)).$fragment;
    }
}
