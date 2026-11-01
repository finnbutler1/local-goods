<?php

/**
 * One-off add-on to stripe-trial-operating-account.php (ADR 0034). Not part
 * of the app; never run by it, by CI or by a deploy.
 *
 * Q6 does Stripe accept a 16-cent transfer from the platform's available
 *    balance (no source_transaction, as a sweep is sent) to a v2
 *    no-dashboard account with no bank account, and does that account's
 *    balance then read exactly 16 cents?
 * Q7 for comparison, does it accept a 1-cent transfer?
 *
 * Safety: same as the first trial. Plain PHP with the Stripe SDK, no
 * Laravel, no database. Test secret key from STRIPE_TRIAL_SECRET only, never
 * printed. Every object is tagged lg_env=scratch, lg_instance=operating-trial,
 * and carries none of the keys reconciliation lists. Cleanup reverses every
 * transfer, refunds the payment and deletes (or closes) the account, then
 * prints everything it made. Saves no fixtures.
 *
 * Run: STRIPE_TRIAL_SECRET=<test secret key> php scripts/stripe-trial-small-sweep.php
 */

require __DIR__.'/../vendor/autoload.php';

use Stripe\StripeClient;

$key = (string) getenv('STRIPE_TRIAL_SECRET');
if (! str_starts_with($key, 'sk_test_')) {
    fwrite(STDERR, "Refusing to run: STRIPE_TRIAL_SECRET must be a Stripe test secret key (sk_test_...). Live keys are never used.\n");
    exit(1);
}

$stripe = new StripeClient(['api_key' => $key, 'max_network_retries' => 1]);
$run = 'small-sweep-'.date('Ymd-His');
$tag = ['lg_env' => 'scratch', 'lg_instance' => 'operating-trial', 'lg_trial' => '1', 'lg_trial_run' => $run];
$created = [];
$answers = [];

function say(string $line): void { fwrite(STDOUT, $line."\n"); }
function note(array &$created, string $kind, string $id, string $what): void { $created[] = [$kind, $id, $what]; say("  made {$kind} {$id}: {$what}"); }
function stripeError(\Stripe\Exception\ApiErrorException $e): string
{
    return ($e->getStripeCode() ?? 'error').' (HTTP '.$e->getHttpStatus().') '.strtok((string) $e->getError()?->message, "\n");
}
function soft(string $what, callable $fn)
{
    try { return $fn(); } catch (\Stripe\Exception\ApiErrorException $e) { say("  FAILED {$what}: ".stripeError($e)); return null; }
}
function usd(array $rows): int { return array_sum(array_map(fn ($r) => $r['currency'] === 'usd' ? $r['amount'] : 0, $rows)); }

$account = $pi = null;
$transfers = [];

try {
    say('1. Throwaway "operating" account: v2, no dashboard, NO bank account');
    $account = $stripe->v2->core->accounts->create([
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
        'include' => ['configuration.recipient'],
        'metadata' => $tag,
    ], ['idempotency_key' => "lg-trial-{$run}-account"]);
    note($created, 'account', $account->id, 'throwaway operating account, no bank');

    $status = null;
    for ($i = 0; $i < 30 && $status !== 'active'; $i++) {
        $a = $stripe->v2->core->accounts->retrieve($account->id, ['include' => ['configuration.recipient']]);
        $status = $a->configuration->recipient->capabilities->stripe_balance->stripe_transfers->status ?? null;
        $status === 'active' || sleep(3);
    }
    say('   transfers capability: '.($status ?? 'unknown'));

    say('2. A $5.00 test payment with funds available at once, so the platform has available balance');
    $pi = $stripe->paymentIntents->create([
        'amount' => 500, 'currency' => 'usd', 'payment_method' => 'pm_card_bypassPending',
        'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
        'confirm' => true, 'description' => 'Local Goods scratch trial (small sweep)', 'metadata' => $tag,
    ], ['idempotency_key' => "lg-trial-{$run}-pi"]);
    note($created, 'payment_intent', $pi->id, '$5.00, status '.$pi->status);

    foreach (['Q6 16-cent transfer from available balance' => 16, 'Q7 1-cent transfer from available balance' => 1] as $q => $cents) {
        say("3. {$q}");
        try {
            $t = $stripe->transfers->create([
                'amount' => $cents, 'currency' => 'usd', 'destination' => $account->id,
                'description' => 'Local Goods scratch trial: small sweep', 'metadata' => $tag,
            ], ['idempotency_key' => "lg-trial-{$run}-t{$cents}"]);
            $transfers[] = $t;
            note($created, 'transfer', $t->id, $cents.' cents');
            $answers[$q] = 'accepted';
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $answers[$q] = 'REFUSED: '.stripeError($e);
        }
    }

    say('4. The operating account balance');
    $bal = $stripe->balance->retrieve([], ['stripe_account' => $account->id])->toArray();
    $answers['Q6 operating balance after the transfers'] = 'available '.usd($bal['available'] ?? []).' cents, pending '.usd($bal['pending'] ?? [])
        .' cents (expected '.array_sum(array_map(fn ($t) => $t->amount, $transfers)).')';
} catch (Throwable $e) {
    $answers['TRIAL STOPPED'] = ($e instanceof \Stripe\Exception\ApiErrorException ? stripeError($e) : get_class($e).': '.$e->getMessage()).'. Cleanup below still ran.';
}

say('5. Cleanup');
foreach ($transfers as $t) {
    $fresh = $stripe->transfers->retrieve($t->id);
    if (($left = $fresh->amount - $fresh->amount_reversed) > 0) {
        $r = soft('reverse '.$t->id, fn () => $stripe->transfers->createReversal($t->id, ['amount' => $left, 'metadata' => $tag + ['lg_trial_step' => 'cleanup']], ['idempotency_key' => "lg-trial-{$run}-rev-{$t->id}"]));
        $r && note($created, 'transfer_reversal', $r->id, 'cleanup of '.$t->id);
    }
}
if ($pi !== null) {
    $c = $stripe->charges->retrieve($pi->latest_charge);
    if (($left = $c->amount - $c->amount_refunded) > 0) {
        $r = soft('refund '.$pi->id, fn () => $stripe->refunds->create(['payment_intent' => $pi->id, 'amount' => $left, 'metadata' => $tag + ['lg_trial_step' => 'cleanup']], ['idempotency_key' => "lg-trial-{$run}-refund"]));
        $r && note($created, 'refund', $r->id, 'cleanup, status '.$r->status);
    }
}
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
