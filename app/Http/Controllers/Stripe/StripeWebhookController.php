<?php

namespace App\Http\Controllers\Stripe;

use App\Http\Controllers\Controller;
use App\Services\Payments\StripeWebhookHandler;
use App\Support\StripeMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * POST /stripe/webhook. Stripe's signature is the only way in: the route is
 * outside the web group (routes/stripe.php), so there is no session, no
 * cookie, no CSRF token and no signed-in user, judge or otherwise. The
 * signature is checked on the raw body exactly as Stripe sent it, which
 * CloudFront passes through unchanged, with the Stripe-Signature header.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookHandler $handler): JsonResponse
    {
        if (! StripeMode::active()) {
            return response()->json(['error' => 'Stripe is not configured on this server.'], 400);
        }

        $event = null;

        foreach (StripeMode::webhookSecrets() as $secret) {
            try {
                $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
                break;
            } catch (SignatureVerificationException) {
                continue;
            } catch (UnexpectedValueException) {
                return response()->json(['error' => 'Invalid payload.'], 400);
            }
        }

        if ($event === null) {
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        if ($event->livemode) {
            return response()->json(['error' => 'Live-mode events are refused: Local Goods runs in Stripe test mode only.'], 400);
        }

        return response()->json(['received' => true, 'outcome' => $handler->handle($event->toArray())]);
    }
}
