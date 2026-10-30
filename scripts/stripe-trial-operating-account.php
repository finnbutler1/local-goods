<?php

/**
 * One-off trial for ADR 0034 (customer funds and Local Goods Operating). Not
 * part of the app; never run by it, by CI or by a deploy.
 *
 * Q1 does a v2 no-dashboard recipient account with no bank account get
 *    stripe_transfers active, and accept transfers (with and without
 *    source_transaction)?
 * Q2 does v1 /balance with Stripe-Account work for a v2 account?
 * Q3 does embedded Checkout accept and echo custom_text.submit.message?
 * Q4 what card fee did Stripe take on a test payment, from which balance?
 * Q5 read-only: production-tagged transfers that carry lg_merchant_fee_cents.
 *
 * Safety: plain PHP with the Stripe SDK, no Laravel, no database. Test
 * secret key from STRIPE_TRIAL_SECRET only, never printed. Every object is
 * tagged lg_env=scratch, lg_instance=operating-trial, and carries none of
 * the keys reconciliation lists. Cleanup reverses every transfer, refunds
 * the payment, expires the Checkout Session and deletes (or closes) the
 * account, then prints the final state of everything it made.
 *
 * Run: STRIPE_TRIAL_SECRET=<test secret key> php scripts/stripe-trial-operating-account.php
 */

require __DIR__.'/../vendor/autoload.php';

use Stripe\StripeClient;

$key = (string) getenv('STRIPE_TRIAL_SECRET');
if (! str_starts_with($key, 'sk_test_')) {
    fwrite(STDERR, "Refusing to run: STRIPE_TRIAL_SECRET must be a Stripe test secret key (sk_test_...). Live keys are never used.\n");
    exit(1);
}

$stripe = new StripeClient(['api_key' => $key, 'max_network_retries' => 1]);
$run = 'operating-'.date('Ymd-His');
$tag = ['lg_env' => 'scratch', 'lg_instance' => 'operating-trial', 'lg_trial' => '1', 'lg_trial_run' => $run];
$created = [];
$started = time() - 5;
$fixtures = __DIR__.'/../tests/Fixtures/stripe/operating';
$answers = [];

function say(string $line): void { fwrite(STDOUT, $line."\n"); }
function note(array &$created, string $kind, string $id, string $what): void { $created[] = [$kind, $id, $what]; say("  made {$kind} {$id}: {$what}"); }
function class_basename($o): string { return substr(strrchr('\\'.get_class($o), '\\'), 1); }
function stripeError(\Stripe\Exception\ApiErrorException $e): string
{
    return ($e->getStripeCode() ?? class_basename($e)).' (HTTP '.$e->getHttpStatus().') '.strtok((string) $e->getError()?->message, "\n");
}
final class StepFailed extends RuntimeException {}
function must(string $what, callable $fn)
{
    try { return $fn(); } catch (\Stripe\Exception\ApiErrorException $e) { say("  FAILED {$what}: ".stripeError($e)); throw new StepFailed($what.': '.stripeError($e)); }
}
/** A question whose answer may be "Stripe refused": recorded, the trial goes on. */
function ask(array &$answers, string $q, callable $fn)
{
    try { return $fn(); } catch (\Stripe\Exception\ApiErrorException $e) { $answers[$q] = 'REFUSED: '.stripeError($e); say("  refused: {$q}: ".stripeError($e)); return null; }
}
function soft(string $what, callable $fn)
{
    try { return $fn(); } catch (\Stripe\Exception\ApiErrorException $e) { say("  FAILED {$what}: ".stripeError($e)); return null; }
}
function usd(array $rows): int { return array_sum(array_map(fn ($r) => $r['currency'] === 'usd' ? $r['amount'] : 0, $rows)); }

$account = $pi = $session = null;
$transfers = [];
$responses = [];

try {
    say('1. Throwaway "operating" account: v2, no dashboard, Stripe test identity, NO bank account');
    $account = must('create account', fn () => $stripe->v2->core->accounts->create([
        'dashboard' => 'none',
        'display_name' => 'Local Goods Operating scratch trial '.$run,
        'contact_email' => 'scratch-trial@example.com',
        'identity' => [
            'country' => 'US',
            'entity_type' => 'individual',
            'individual' => [
                'given_name' => 'Demo', 'surname' => 'Owner',
                'date_of_birth' => ['day' => 1, 'month' => 1, 'year' => 1901],
                'id_numbers' => [['type' => 'us_ssn', 'value' => '000000000']],
                'address' => ['line1' => 'address_full_match', 'city' => 'Washington', 'state' => 'DC', 'postal_code' => '20006', 'country' => 'US'],
                'email' => 'scratch-trial@example.com',
                'phone' => '+12025550100',
            ],
            'attestations' => ['terms_of_service' => ['account' => ['date' => gmdate('Y-m-d\TH:i:s\Z'), 'ip' => '127.0.0.1']]],
        ],
        'defaults' => [
            'responsibilities' => ['fees_collector' => 'application', 'losses_collector' => 'application'],
            'profile' => ['product_description' => 'Scratch trial of a Local Goods operating account in test mode. Deleted after the trial.'],
        ],
        'configuration' => ['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]],
        'include' => ['configuration.recipient', 'requirements'],
        'metadata' => $tag,
    ], ['idempotency_key' => "lg-trial-{$run}-account"]));
    note($created, 'account', $account->id, 'throwaway operating account, no bank');

    say('   waiting for transfers to turn on (up to 90 s), with no bank account...');
    $status = null;
    for ($i = 0; $i < 30 && $status !== 'active'; $i++) {
        $a = $stripe->v2->core->accounts->retrieve($account->id, ['include' => ['configuration.recipient', 'requirements']]);
        $status = $a->configuration->recipient->capabilities->stripe_balance->stripe_transfers->status ?? null;
        $status === 'active' || sleep(3);
    }
    $due = array_map(fn ($e) => $e->description ?? $e->awaiting_action_from ?? '?', $a->requirements->entries ?? []);
    $answers['Q1 stripe_transfers status with no bank account'] = ($status ?? 'unknown').($due ? '; requirements: '.implode(' | ', array_slice($due, 0, 5)) : '');
    $responses['account'] = $a->toArray();

    say('2. A $5.00 test payment with funds available at once (pm_card_bypassPending)');
    $pi = must('payment', fn () => $stripe->paymentIntents->create([
        'amount' => 500, 'currency' => 'usd', 'payment_method' => 'pm_card_bypassPending',
        'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
        'confirm' => true, 'description' => 'Local Goods scratch trial (operating account)', 'metadata' => $tag,
    ], ['idempotency_key' => "lg-trial-{$run}-pi"]));
    note($created, 'payment_intent', $pi->id, '$5.00, status '.$pi->status);
    $charge = $stripe->charges->retrieve($pi->latest_charge, ['expand' => ['balance_transaction']]);
    $bt = $charge->balance_transaction;
    $answers['Q4 Stripe card fee on a $5.00 test payment'] = $bt ? ($bt->fee.' cents, taken from the platform balance (net '.$bt->net.' of '.$bt->amount.')') : 'no balance transaction yet';

    say('3. Transfers to the operating account: $1.00 with source_transaction, $0.50 from the available balance');
    $t1 = ask($answers, 'Q1 transfer with source_transaction', fn () => $stripe->transfers->create([
        'amount' => 100, 'currency' => 'usd', 'destination' => $account->id, 'source_transaction' => $pi->latest_charge,
        'description' => 'Local Goods scratch trial: sweep with source_transaction', 'metadata' => $tag,
    ], ['idempotency_key' => "lg-trial-{$run}-t1"]));
    if ($t1) { $transfers[] = $t1; note($created, 'transfer', $t1->id, '$1.00 with source_transaction'); $answers['Q1 transfer with source_transaction'] = 'accepted'; }
    $t2 = ask($answers, 'Q1 transfer from available balance', fn () => $stripe->transfers->create([
        'amount' => 50, 'currency' => 'usd', 'destination' => $account->id,
        'description' => 'Local Goods scratch trial: sweep from available balance', 'metadata' => $tag,
    ], ['idempotency_key' => "lg-trial-{$run}-t2"]));
    if ($t2) { $transfers[] = $t2; note($created, 'transfer', $t2->id, '$0.50 from available balance'); $answers['Q1 transfer from available balance'] = 'accepted'; }

    say('4. The operating account\'s own balance (v1 /balance with Stripe-Account)');
    $bal = ask($answers, 'Q2 /balance with Stripe-Account', fn () => $stripe->balance->retrieve([], ['stripe_account' => $account->id]));
    if ($bal) {
        $responses['balance'] = $bal->toArray();
        $answers['Q2 /balance with Stripe-Account'] = 'works: available '.usd($responses['balance']['available'] ?? []).' cents, pending '.usd($responses['balance']['pending'] ?? [])
            .' cents (expected '.array_sum(array_map(fn ($t) => $t->amount, $transfers)).')';
    }
    $payouts = ask($answers, 'Q2 payouts on the operating account', fn () => $stripe->payouts->all(['limit' => 5], ['stripe_account' => $account->id]));
    if ($payouts) { $answers['Q2 payouts on the operating account'] = count($payouts->data).' payouts'; }

    say('5. Embedded Checkout with custom_text.submit.message');
    $message = 'Your money is held in the Local Goods customer funds account and stays yours until your staff spend it. It is never used for Local Goods\' own expenses. (Scratch trial.)';
    $session = ask($answers, 'Q3 embedded Checkout custom_text', fn () => $stripe->checkout->sessions->create([
        'mode' => 'payment', 'ui_mode' => 'embedded_page',
        'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => 100, 'product_data' => ['name' => 'Local Goods scratch trial']]]],
        'custom_text' => ['submit' => ['message' => $message]],
        'payment_intent_data' => ['description' => 'Local Goods scratch trial', 'metadata' => $tag],
        'metadata' => $tag,
        'return_url' => 'https://example.com/return',
    ], ['idempotency_key' => "lg-trial-{$run}-checkout"]));
    if ($session) {
        note($created, 'checkout_session', $session->id, 'embedded, $1.00, never paid');
        $answers['Q3 embedded Checkout custom_text'] = 'accepted; echoed back: '.(($session->custom_text->submit->message ?? null) === $message ? 'yes, exactly' : 'NO');
    }
} catch (StepFailed $e) {
    $answers['TRIAL STOPPED at'] = $e->getMessage().'. Later steps were not run; cleanup below still ran.';
} catch (Throwable $e) {
    $answers['TRIAL STOPPED (unexpected error)'] = class_basename($e).': '.$e->getMessage().'. Cleanup below still ran.';
}

say('6. Q5, read-only: production-tagged transfers carrying a Local Goods merchant fee');
$found = [];
foreach ($stripe->transfers->all(['limit' => 100])->autoPagingIterator() as $t) {
    if (($t->metadata->lg_env ?? null) === 'production' && isset($t->metadata->lg_merchant_fee_cents)) {
        $found[] = gmdate('Y-m-d H:i', $t->created).' UTC: transfer '.$t->amount.' cents, fee '.$t->metadata->lg_merchant_fee_cents.' cents, redemption #'.($t->metadata->lg_redemption_id ?? '?');
    }
}
$answers['Q5 production transfers with a merchant fee'] = $found ? count($found).': '.implode('; ', $found) : 'none';

say('7. Fixtures (scrubbed): account, balance, and the transfer.created events');
sleep(10);
$ids = array_column($created, 1);
foreach ($stripe->events->all(['created' => ['gte' => $started], 'types' => ['transfer.created', 'account.updated', 'v2.core.account.updated'], 'limit' => 100])->autoPagingIterator() as $event) {
    $o = $event->data->object;
    if (in_array($o->id ?? null, $ids, true) || ($o->metadata->lg_trial_run ?? null) === $run) {
        $responses['event-'.str_replace('.', '-', $event->type).'-'.count($responses)] = $event->toArray();
    }
}
$map = [];
$scrub = function ($value) use (&$scrub, &$map) {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            if (in_array($k, ['client_secret', 'receipt_email', 'receipt_url', 'email', 'contact_email', 'phone', 'idempotency_key', 'fingerprint', 'last4', 'url', 'ip'], true)) { $out[$k] = $v === null ? null : 'fake-removed'; continue; }
            if (in_array($k, ['lg_trial_run', 'transfer_group'], true)) { $out[$k] = $v === null ? null : 'fake-run'; continue; }
            $out[$k] = $scrub($v);
        }
        return $out;
    }
    if (is_string($value)) {
        return preg_replace_callback('/\b([a-z]{2,6})_(?=[a-z]*[0-9A-Z])[A-Za-z0-9]{8,}\b/', fn ($m) => str_starts_with($m[0], 'fake_') ? $m[0] : ($map[$m[0]] ??= 'fake_'.$m[1].'_'.(count($map) + 1)), $value);
    }
    return $value;
};
is_dir($fixtures) || mkdir($fixtures, 0755, true);
foreach ($responses as $name => $body) {
    file_put_contents("{$fixtures}/{$name}.json", json_encode($scrub($body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}
say('   saved '.count($responses).' scrubbed fixtures in tests/Fixtures/stripe/operating/');

say('8. Cleanup');
foreach ($transfers as $t) {
    $fresh = $stripe->transfers->retrieve($t->id);
    if (($left = $fresh->amount - $fresh->amount_reversed) > 0) {
        $r = soft('reverse '.$t->id, fn () => $stripe->transfers->createReversal($t->id, ['amount' => $left, 'metadata' => $tag + ['lg_trial_step' => 'cleanup']], ['idempotency_key' => "lg-trial-{$run}-rev-{$t->id}"]));
        $r && note($created, 'transfer_reversal', $r->id, 'cleanup of '.$t->id);
    }
}
$answers['Pulled back from the operating account by the platform (honesty point)'] = count($transfers) ? 'yes, by transfer reversal' : 'no transfers made';
if ($pi !== null) {
    $c = $stripe->charges->retrieve($pi->latest_charge);
    if (($left = $c->amount - $c->amount_refunded) > 0) {
        $r = soft('refund '.$pi->id, fn () => $stripe->refunds->create(['payment_intent' => $pi->id, 'amount' => $left, 'metadata' => $tag + ['lg_trial_step' => 'cleanup']], ['idempotency_key' => "lg-trial-{$run}-refund"]));
        $r && note($created, 'refund', $r->id, 'cleanup, status '.$r->status);
    }
}
$session && soft('expire checkout', fn () => $stripe->checkout->sessions->expire($session->id));
if ($account !== null) {
    if (soft('delete account', fn () => $stripe->accounts->delete($account->id)) === null) {
        $closed = soft('close account (v2)', fn () => $stripe->v2->core->accounts->close($account->id, ['applied_configurations' => ['recipient']]));
        say('   account '.($closed ? 'closed' : 'NOT removed: delete it by hand in the Stripe dashboard'));
    } else {
        say('   account deleted');
    }
}

say('');
say('Every object made:');
foreach ($created as [$kind, $id, $what]) { say("  {$kind} {$id}: {$what}"); }
say('');
say('Answers:');
foreach ($answers as $q => $a) { say("  {$q}: {$a}"); }
