<?php

namespace App\Support;

use App\Services\ReviewerLinks;
use Carbon\Exceptions\InvalidFormatException;
use ValueError;

/**
 * The full public judge link, read from PUBLIC_JUDGE_URL (only a hash of a
 * magic link is stored, so the app can't rebuild it). Anything but a working
 * https://uselocalgoods.com/judge-access link that opens the shared public
 * account counts as not set, so a named reviewer's link pasted by mistake is
 * never published and a link past the end of judging hides itself.
 */
class PublicJudgeLink
{
    private const SHAPE = '/\Ahttps:\/\/uselocalgoods\.com\/judge-access#(\d{1,10}\.[A-Za-z0-9]{48})\z/';

    public function __construct(private ReviewerLinks $links) {}

    public function url(): ?string
    {
        $url = config('reviewers.public_judge_url');

        if (! is_string($url) || ! preg_match(self::SHAPE, $url, $parts)) {
            return null;
        }

        // A bad end date or an unknown role on the row must not take the page
        // down with it. The error is still logged; neither message holds the link.
        try {
            return $this->links->userFor($parts[1])?->isPublicReviewer() ? $url : null;
        } catch (InvalidFormatException|ValueError $e) {
            report($e);

            return null;
        }
    }
}
