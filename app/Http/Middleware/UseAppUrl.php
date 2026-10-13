<?php

namespace App\Http\Middleware;

use App\Support\AppUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In production the site is reached through CloudFront over HTTPS, but
 * CloudFront calls Beanstalk over plain HTTP and doesn't pass on the address
 * the visitor typed. So Laravel would see "http://<beanstalk-host>" and build
 * links, redirects and "back" URLs that skip CloudFront. This makes every
 * production request, and every URL built from it, use APP_URL instead.
 * Production has exactly one real address, so nothing legitimate is lost.
 */
class UseAppUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction()) {
            AppUrl::force();
            AppUrl::rewrite($request);
        }

        return $next($request);
    }
}
