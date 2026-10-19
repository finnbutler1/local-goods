<?php

use App\Http\Controllers\Stripe\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Stripe's webhook, loaded outside the "web" middleware group on purpose
 * (bootstrap/app.php): no session, no cookies and no CSRF token, so no one
 * is ever signed in here and the judge write-block has nothing to judge.
 * The Stripe signature is the only authentication (ADR 0015).
 */
Route::post('/stripe/webhook', StripeWebhookController::class)
    ->middleware('throttle:300,1,stripe.webhook')
    ->name('stripe.webhook');
