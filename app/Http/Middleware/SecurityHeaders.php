<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on web responses. Added to the "web" group only, so
 * /stripe/webhook (api group) and /up (health check) are untouched.
 *
 * The Content-Security-Policy is REPORT-ONLY on purpose: it logs what it
 * would block in the browser console and blocks nothing, so it can't break
 * the demo. frame-ancestors is ignored in report-only mode; clickjacking is
 * stopped by X-Frame-Options: DENY.
 */
class SecurityHeaders
{
    public const STRIPE_SCRIPT = 'https://js.stripe.com';

    public const STRIPE_CONNECT = ['https://api.stripe.com', 'https://m.stripe.network', 'https://r.stripe.com'];

    public const STRIPE_FRAMES = ['https://js.stripe.com', 'https://hooks.stripe.com', 'https://checkout.stripe.com'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        // "payment" is left alone: Stripe's embedded Checkout uses it.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy-Report-Only', $this->policy());

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    public function policy(): string
    {
        $dev = $this->viteDevOrigins();

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' ".self::STRIPE_SCRIPT.$dev,
            "style-src 'self' 'unsafe-inline'".$dev,
            "img-src 'self' data: https://*.stripe.com",
            "font-src 'self' data:".$dev,
            "connect-src 'self' ".implode(' ', self::STRIPE_CONNECT).$dev,
            'frame-src '.implode(' ', self::STRIPE_FRAMES),
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }

    /** Vite's dev server (only while public/hot exists, i.e. locally). */
    private function viteDevOrigins(): string
    {
        $hot = public_path('hot');
        if (! is_file($hot)) {
            return '';
        }
        $origin = rtrim(trim((string) file_get_contents($hot)), '/');

        return $origin === '' ? '' : ' '.$origin.' '.str_replace('http', 'ws', $origin);
    }
}
