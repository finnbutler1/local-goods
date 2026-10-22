<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Pins generated URLs, and the incoming request's own address, to APP_URL
 * over HTTPS. Used in production only (see UseAppUrl and AppServiceProvider).
 */
class AppUrl
{
    /** Every link, redirect and form action Laravel builds starts with APP_URL, over https. */
    public static function force(): void
    {
        URL::forceRootUrl(rtrim((string) config('app.url'), '/'));
        URL::forceScheme('https');
    }

    /**
     * A named route as a full URL for somebody outside to come back to (for
     * example Stripe's return_url). In production always APP_URL, never the
     * host this request arrived on: Beanstalk refuses direct visitors.
     */
    public static function route(string $name, array $parameters = []): string
    {
        return app()->isProduction()
            ? rtrim((string) config('app.url'), '/').route($name, $parameters, false)
            : route($name, $parameters);
    }

    /** Makes the request see itself as https://<APP_URL host>, whatever host and scheme it arrived with. */
    public static function rewrite(Request $request): void
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! $host) {
            return;
        }

        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        $request->headers->set('Host', $port ? "{$host}:{$port}" : $host);
        $request->server->set('HTTP_HOST', $request->headers->get('Host'));
        $request->server->set('HTTPS', 'on');
        $request->server->set('SERVER_PORT', $port ?: 443);
    }
}
