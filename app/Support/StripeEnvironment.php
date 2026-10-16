<?php

namespace App\Support;

/**
 * Which copy of Local Goods made a Stripe object. Local development and
 * production can share one Stripe sandbox, so every payment, transfer and
 * connected account we create carries lg_env (APP_ENV) and lg_instance (a
 * short id for this server) in its metadata. Reconciliation counts only
 * this environment's objects, and the webhook ignores events about another
 * environment's (ADR 0015).
 *
 * The instance id is an HMAC of a fixed label with APP_KEY: stable for as
 * long as the server keeps its key, different between servers, and it
 * reveals nothing about the key itself.
 */
class StripeEnvironment
{
    public const SAME = 'same';

    public const OTHER = 'other';

    public const UNTAGGED = 'untagged';

    /** @return array{lg_env: string, lg_instance: string} */
    public static function tag(): array
    {
        return ['lg_env' => (string) config('app.env'), 'lg_instance' => self::instance()];
    }

    public static function instance(): string
    {
        return substr(hash_hmac('sha256', 'local-goods-stripe-instance', (string) config('app.key')), 0, 8);
    }

    /** "production · 1a2b3c4d", for the reconciliation page. */
    public static function label(?array $metadata = null): string
    {
        $metadata ??= self::tag();

        return ($metadata['lg_env'] ?? '?').' · '.($metadata['lg_instance'] ?? '?');
    }

    /** @return self::SAME|self::OTHER|self::UNTAGGED */
    public static function of(?array $metadata): string
    {
        if (! isset($metadata['lg_env']) && ! isset($metadata['lg_instance'])) {
            return self::UNTAGGED;
        }

        return ($metadata['lg_env'] ?? null) === self::tag()['lg_env'] && ($metadata['lg_instance'] ?? null) === self::instance()
            ? self::SAME
            : self::OTHER;
    }
}
