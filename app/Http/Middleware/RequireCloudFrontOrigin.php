<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In production, only CloudFront may talk to Beanstalk. Anyone who finds the
 * raw elasticbeanstalk.com address would otherwise reach the site over plain
 * http, around CloudFront. A request without the X-Origin-Verify secret
 * (CLOUDFRONT_ORIGIN_SECRET) gets a plain 403. Beanstalk's own health check
 * (/up) is let through, since it calls the instance directly.
 *
 * Kill switch: ORIGIN_VERIFY_ENFORCE=false in Beanstalk turns this off
 * without a redeploy, in case CloudFront stops sending the header. The deploy
 * hook (app:check-production-config) refuses an empty secret while it's on.
 */
class RequireCloudFrontOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction()
            && config('services.cloudfront.enforce')
            && ! $request->is('up')
            && ! TrustCloudFront::cameThroughCloudFront($request)) {
            return response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $next($request);
    }
}
