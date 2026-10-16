<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;

/**
 * Stripe's informational notices must never crash a request.
 *
 * stripe-php 22 turns a "Stripe-Notice" response header (for example "We
 * recommend building your integration using Accounts v2") into a PHP
 * E_USER_WARNING, which Laravel converts into an ErrorException: a 500,
 * after Stripe has already done the work. The official switch,
 * STRIPE_SUPPRESS_NOTICES=true, is set too (StripeSdkGateway), but the
 * library ignores it when it detects an AI coding agent in the environment
 * (CLAUDECODE, CURSOR_AGENT, ...), so it can't be relied on alone. This
 * catches user-level notices and warnings raised during a Stripe call and
 * logs them (Stripe's own text, with anything key-like redacted) instead.
 * Real PHP errors (E_WARNING and up) still go to Laravel as before.
 */
class StripeNotices
{
    public const CAUGHT = E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED;

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public static function quietly(string $what, callable $call): mixed
    {
        // No level mask on purpose: with one, PHP would send every other error
        // to its built-in handler instead of Laravel's. Anything that isn't a
        // user-level notice goes on to the handler that was there before.
        $previous = null;
        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use ($what, &$previous) {
            if ($level & self::CAUGHT) {
                Log::notice('Stripe notice (not an error)', ['call' => $what, 'notice' => self::safe($message)]);

                return true;
            }

            return $previous ? $previous($level, $message, $file, $line) : false;
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /** First line, shortened, with anything that looks like a key or secret removed. */
    public static function safe(string $message): string
    {
        return mb_strimwidth(self::redact(strtok($message, "\r\n") ?: ''), 0, 200, '…');
    }

    /** Removes anything that looks like a Stripe key or webhook secret. */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/\b(?:sk|pk|rk)_(?:test|live)_\S+|\bwhsec_\S+/', '[redacted]', $text);
    }
}
