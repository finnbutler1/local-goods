<?php

namespace App\Support;

/**
 * Long lists show their newest few rows and a "Show all (N)" button that
 * reveals the rest in place (`<x-show-more>`, `[data-show-more]` in app.js).
 * The page carries at most CAP rows per list; a list with more keeps its
 * "reload with everything" link, so no row becomes unreachable. Display only:
 * it never changes which rows exist, only how many a page loads and shows.
 */
final class ShowMore
{
    /** Most rows one list puts in the page unless the person asks for them all. */
    public const CAP = 50;

    /** How many rows each list shows before the button. */
    public const PAYOUTS = 3;

    public const RECEIVED = 5;

    public const RECENT_REDEMPTIONS = 3;

    public const HISTORY = 5;

    public const PURCHASES = 5;

    public const GIVEN = 5;

    public const ACTIVITY = 5;
}
