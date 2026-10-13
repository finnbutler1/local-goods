<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Finds the real visitor's IP address (used to rate-limit logins) behind
 * CloudFront, without letting anyone fake it.
 *
 * CloudFront adds the visitor's IP to the end of X-Forwarded-For. But anyone
 * can send that header, so it is only believed when the request carries the
 * X-Origin-Verify secret that CloudFront adds on its way to Beanstalk
 * (CLOUDFRONT_ORIGIN_SECRET). Then only the caller (the CloudFront edge) is
 * trusted, so the right-most entry, the one CloudFront wrote, is the IP; any
 * entries the visitor wrote further left are ignored. Without the secret,
 * nothing is trusted and the IP is whoever actually connected.
 *
 * Replaces Laravel's own TrustProxies (bootstrap/app.php).
 */
class TrustCloudFront extends TrustProxies
{
    public const SECRET_HEADER = 'X-Origin-Verify';

    public function handle(Request $request, Closure $next)
    {
        $request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        if ($this->cameThroughCloudFront($request)) {
            $request::setTrustedProxies([$request->server->get('REMOTE_ADDR')], Request::HEADER_X_FORWARDED_FOR);
        }

        return $next($request);
    }

    private function cameThroughCloudFront(Request $request): bool
    {
        $secret = (string) config('services.cloudfront.origin_secret');
        $sent = $request->headers->get(self::SECRET_HEADER);

        return $secret !== '' && is_string($sent) && hash_equals($secret, $sent);
    }
}
