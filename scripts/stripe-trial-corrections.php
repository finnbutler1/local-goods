<?php

/**
 * One-off trial tool for step 7 (refunds and corrections, ADR 0028). Not
 * part of the app and never run by it, by CI or by a deploy.
 *
 * Asks Stripe's test mode the questions the corrections code depends on,
 * then saves the real webhook payloads (scrubbed) as test fixtures:
 * - does transfer.reversed carry each reversal's metadata (partial, then full)?
 * - what do refund.created / refund.updated / charge.refunded look like, and
 *   in what order, for a charge whose funds are available and one still pending?
 * - does repeating a reversal or refund with the same idempotency key return
 *   the same object?
 *
 * Safety:
 * - Plain PHP with the Stripe SDK only. It does not boot Laravel and opens
 *   no database: it only calls Stripe and writes JSON under
 *   tests/Fixtures/stripe/corrections/.
 * - Reads the key from STRIPE_TRIAL_SECRET only, and refuses anything that
 *   is not a test secret key. Never prints the key.
 * - Every object is tagged lg_env=scratch, lg_instance=step7-trial, so local
 *   and live Local Goods treat it as another environment (webhook ignores
 *   it, reconciliation never counts it). No lg_purchase_token, lg_payout_key
 *   or lg_correction_key, so reconciliation doesn't even list it.
 * - Cleans up: refunds both charges in full, reverses whatever is still
 *   transferred, deletes (or closes) the throwaway connected account, and
 *   prints every object it made with its final state.
 *
 * Run: STRIPE_TRIAL_SECRET=<your test secret key> php scripts/stripe-trial-corrections.php
 */

require __DIR__.'/../vendor/autoload.php';

use Stripe\StripeClient;

$key = (string) getenv('STRIPE_TRIAL_SECRET');
if (! str_starts_with($key, 'sk_test_')) {
    fwrite(STDERR, "Refusing to run: STRIPE_TRIAL_SECRET must be a Stripe test secret key (sk_test_...). Live keys are never used.\n");
    exit(1);
}

$stripe = new StripeClient(['api_key' => $key, 'max_network_retries' => 1]);
$run = 'step7-'.date('Ymd-His');
$tag = ['lg_env' => 'scratch', 'lg_instance' => 'step7-trial', 'lg_trial' => '1', 'lg_trial_run' => $run];
$created = [];   // [kind, id, note]
$started = time() - 5;
$fixtures = __DIR__.'/../tests/Fixtures/stripe/corrections';

function say(string $line): void
{
    fwrite(STDOUT, $line."\n");
}

function note(array &$created, string $kind, string $id, string $what): void
{
    $created[] = [$kind, $id, $what];
    say("  made {$kind} {$id}: {$what}");
}

final class StepFailed extends RuntimeException {}

function stripeError(\Stripe\Exception\ApiErrorException $e): string
{
    return ($e->getStripeCode() ?? class_basename($e)).' (HTTP '.$e->getHttpStatus().') '.strtok((string) $e->getError()?->message, "\n");
}

/** A trial step. A failure stops the trial (StepFailed); cleanup still runs. */
function must(string $what, callable $fn)
{
    try {
        return $fn();
    } catch (\Stripe\Exception\ApiErrorException $e) {
        say("  FAILED {$what}: ".stripeError($e));

        throw new StepFailed($what.': '.stripeError($e));
    }
}

/** Cleanup and read-back only: a failure is reported and the next cleanup step still runs. */
function soft(string $what, callable $fn)
{
    try {
        return $fn();
    } catch (\Stripe\Exception\ApiErrorException $e) {
        say("  FAILED {$what}: ".stripeError($e));

        return null;
    }
}

function class_basename($o): string
{
    return substr(strrchr('\\'.get_class($o), '\\'), 1);
}

$account = $piA = $piB = $transfer = null;
$answers = [];
$completed = false;

try {
    say('1. Throwaway connected account (v2, no dashboard, Stripe test identity)');
    $account = must('create account', fn () => $stripe->v2->core->accounts->create([
        'dashboard' => 'none',
        'display_name' => 'Local Goods scratch trial '.$run,
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
            'profile' => ['product_description' => 'Scratch trial account for Local Goods test mode. Deleted after the trial.'],
        ],
        'configuration' => ['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]],
        'include' => ['configuration.recipient', 'requirements'],
        'metadata' => $tag,
    ], ['idempotency_key' => "lg-trial-{$run}-account"]));

    note($created, 'account', $account->id, 'throwaway recipient');
    must('add test bank account', fn () => $stripe->accounts->createExternalAccount($account->id, ['external_account' => 'btok_us_verified'], ['idempotency_key' => "lg-trial-{$run}-bank"]));

    say('   waiting for transfers to turn on (up to 90 s)...');
    $active = false;
    for ($i = 0; $i < 30 && ! $active; $i++) {
        $a = $stripe->v2->core->accounts->retrieve($account->id, ['include' => ['configuration.recipient']]);
        $status = $a->configuration->recipient->capabilities->stripe_balance->stripe_transfers->status ?? null;
        $active = $status === 'active';
        $active || sleep(3);
    }
    say('   transfers capability: '.($active ? 'active' : 'not active yet (transfers below may be refused)'));

    say('2. Two test payments: A with funds available at once, B with funds still pending');
    $piA = must('payment A', fn () => $stripe->paymentIntents->create([
        'amount' => 3000, 'currency' => 'usd', 'payment_method' => 'pm_card_bypassPending',
        'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
        'confirm' => true, 'transfer_group' => "LG-TRIAL-{$run}", 'description' => 'Local Goods scratch trial (A)', 'metadata' => $tag,
    ], ['idempotency_key' => "lg-trial-{$run}-pi-a"]));
    if ($piA->status !== 'succeeded') {
        throw new StepFailed('payment A: Stripe made it but its status is '.$piA->status.', not succeeded');
    }
    $piA && note($created, 'payment_intent', $piA->id, '$30.00, available balance, charge '.$piA->latest_charge);
    $piB = must('payment B', fn () => $stripe->paymentIntents->create([
        'amount' => 2000, 'currency' => 'usd', 'payment_method' => 'pm_card_visa',
        'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
        'confirm' => true, 'description' => 'Local Goods scratch trial (B)', 'metadata' => $tag,
    ], ['idempotency_key' => "lg-trial-{$run}-pi-b"]));
    if ($piB->status !== 'succeeded') {
        throw new StepFailed('payment B: Stripe made it but its status is '.$piB->status.', not succeeded');
    }
    $piB && note($created, 'payment_intent', $piB->id, '$20.00, pending balance, charge '.$piB->latest_charge);

    if ($piA !== null) {
        say('3. Transfer $15.00 from charge A, then a partial and a full reversal with metadata');
        $transfer = must('transfer', fn () => $stripe->transfers->create([
            'amount' => 1500, 'currency' => 'usd', 'destination' => $account->id, 'transfer_group' => "LG-TRIAL-{$run}",
            'source_transaction' => $piA->latest_charge, 'description' => 'Local Goods scratch trial transfer', 'metadata' => $tag,
        ], ['idempotency_key' => "lg-trial-{$run}-transfer"]));

        if ($transfer !== null) {
            note($created, 'transfer', $transfer->id, '$15.00');
            $partialKey = "lg-trial-{$run}-reversal-1";
            $partial = must('partial reversal', fn () => $stripe->transfers->createReversal($transfer->id, [
                'amount' => 500, 'description' => 'Local Goods scratch trial: partial refund', 'metadata' => $tag + ['lg_trial_step' => 'partial'],
            ], ['idempotency_key' => $partialKey]));
            if ($partial !== null) {
                note($created, 'transfer_reversal', $partial->id, '$5.00 partial');
                $again = must('repeat partial reversal, same key', fn () => $stripe->transfers->createReversal($transfer->id, [
                    'amount' => 500, 'description' => 'Local Goods scratch trial: partial refund', 'metadata' => $tag + ['lg_trial_step' => 'partial'],
                ], ['idempotency_key' => $partialKey]));
                $answers['Same idempotency key returns the same reversal'] = $again !== null && $again->id === $partial->id ? 'yes' : 'NO';
            }
            $full = must('rest of the reversal', fn () => $stripe->transfers->createReversal($transfer->id, [
                'amount' => 1000, 'description' => 'Local Goods scratch trial: rest refunded', 'metadata' => $tag + ['lg_trial_step' => 'rest'],
            ], ['idempotency_key' => "lg-trial-{$run}-reversal-2"]));
            $full && note($created, 'transfer_reversal', $full->id, '$10.00, transfer now fully reversed');
        }

        say('4. Partial refund on A (available funds)');
        $refundKey = "lg-trial-{$run}-refund-a";
        $refundA = must('refund A', fn () => $stripe->refunds->create([
            'payment_intent' => $piA->id, 'amount' => 800, 'reason' => 'requested_by_customer', 'metadata' => $tag + ['lg_trial_step' => 'refund-a'],
        ], ['idempotency_key' => $refundKey]));
        if ($refundA !== null) {
            note($created, 'refund', $refundA->id, '$8.00 on A, status '.$refundA->status);
            $again = must('repeat refund, same key', fn () => $stripe->refunds->create([
                'payment_intent' => $piA->id, 'amount' => 800, 'reason' => 'requested_by_customer', 'metadata' => $tag + ['lg_trial_step' => 'refund-a'],
            ], ['idempotency_key' => $refundKey]));
            $answers['Same idempotency key returns the same refund'] = $again !== null && $again->id === $refundA->id ? 'yes' : 'NO';
            $answers['Refund on available funds, status in the reply'] = $refundA->status;
        }
    }

    if ($piB !== null) {
        say('5. Partial refund on B (funds still pending)');
        $refundB = must('refund B', fn () => $stripe->refunds->create([
            'payment_intent' => $piB->id, 'amount' => 500, 'reason' => 'requested_by_customer', 'metadata' => $tag + ['lg_trial_step' => 'refund-b'],
        ], ['idempotency_key' => "lg-trial-{$run}-refund-b"]));
        if ($refundB !== null) {
            note($created, 'refund', $refundB->id, '$5.00 on B, status '.$refundB->status);
            $answers['Refund on pending funds, status in the reply'] = $refundB->status.(($refundB->status === 'pending') ? ' ('.($refundB->pending_reason ?? '?').')' : '');
        }
    }
    $completed = true;
} catch (StepFailed $e) {
    $answers['TRIAL STOPPED at'] = $e->getMessage().'. Later steps were not run; cleanup below still ran.';
    say('Stopped at the first failed step; going straight to cleanup.');
} catch (Throwable $e) {
    $answers['TRIAL STOPPED (unexpected error)'] = class_basename($e).': '.$e->getMessage().'. Cleanup below still ran.';
    say('Stopped by an unexpected error; going straight to cleanup.');
}

if ($completed) {
say('6. Collecting the events Stripe sent about these objects (waiting 15 s for them)...');
sleep(15);
$ids = array_column($created, 1);
$wanted = ['transfer.created', 'transfer.reversed', 'transfer.updated', 'refund.created', 'refund.updated', 'refund.failed', 'charge.refunded', 'charge.refund.updated'];
$events = [];
foreach ($stripe->events->all(['created' => ['gte' => $started], 'types' => $wanted, 'limit' => 100])->autoPagingIterator() as $event) {
    $object = $event->data->object;
    $related = in_array($object->id, $ids, true)
        || in_array($object->payment_intent ?? null, $ids, true)
        || ($object->metadata->lg_trial_run ?? null) === $run;
    if ($related) {
        $events[] = $event->toArray();
    }
}
$events = array_reverse($events); // oldest first
say('   '.count($events).' events: '.implode(', ', array_map(fn ($e) => $e['type'], $events)));

foreach ($events as $e) {
    if ($e['type'] === 'transfer.reversed') {
        $reversals = $e['data']['object']['reversals']['data'] ?? null;
        $answers['transfer.reversed (amount_reversed '.$e['data']['object']['amount_reversed'].') carries reversals with metadata'] =
            $reversals === null ? 'NO reversals list in the payload' : (count(array_filter($reversals, fn ($r) => isset($r['metadata']['lg_trial_step']))).' of '.count($reversals).' have our metadata');
    }
}

// Scrub: every Stripe id becomes fake_<prefix>_<n> (the same id always maps
// to the same placeholder), emails and secrets are dropped.
$map = [];
$scrub = function ($value, $key = null) use (&$scrub, &$map) {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            if (in_array($k, ['client_secret', 'receipt_email', 'receipt_url', 'email', 'idempotency_key', 'fingerprint', 'last4',
                'authorization_code', 'network_transaction_id', 'reference', 'exp_month', 'exp_year'], true)) {
                $out[$k] = $v === null ? null : 'fake-removed';

                continue;
            }
            if ($k === 'lg_trial_run' || $k === 'transfer_group') {
                $out[$k] = $v === null ? null : 'fake-run';

                continue;
            }
            $out[$k] = $scrub($v, $k);
        }

        return $out;
    }
    if (is_string($value)) {
        // Whole ids and ids inside strings (such as /v1/transfers/tr_.../reversals).
        return preg_replace_callback('/\\b([a-z]{2,6})_(?=[a-z]*[0-9A-Z])[A-Za-z0-9]{8,}\\b/', fn ($m) => str_starts_with($m[0], 'fake_') ? $m[0]
            : ($map[$m[0]] ??= 'fake_'.$m[1].'_'.(count($map) + 1)), $value);
    }

    return $value;
};

if (! is_dir($fixtures)) {
    mkdir($fixtures, 0755, true);
}
foreach ($events as $n => $e) {
    $file = sprintf('%s/%02d-%s.json', $fixtures, $n + 1, str_replace('.', '-', $e['type']));
    file_put_contents($file, json_encode($scrub($e), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}
say('   saved '.count($events).' scrubbed fixtures in tests/Fixtures/stripe/corrections/');
} else {
    say('6. Skipped: no fixtures are saved from a trial that stopped early.');
}

say('7. Cleanup');
foreach ([$piA, $piB] as $pi) {
    if ($pi === null) {
        continue;
    }
    $charge = $stripe->charges->retrieve($pi->latest_charge);
    $left = $charge->amount - $charge->amount_refunded;
    if ($left > 0) {
        $r = soft('refund the rest of '.$pi->id, fn () => $stripe->refunds->create(['payment_intent' => $pi->id, 'amount' => $left, 'metadata' => $tag + ['lg_trial_step' => 'cleanup']], ['idempotency_key' => "lg-trial-{$run}-cleanup-{$pi->id}"]));
        $r && note($created, 'refund', $r->id, 'cleanup, status '.$r->status);
    }
}
if ($transfer !== null) {
    $t = $stripe->transfers->retrieve($transfer->id);
    $left = $t->amount - $t->amount_reversed;
    if ($left > 0) {
        $r = soft('reverse the rest of the transfer', fn () => $stripe->transfers->createReversal($transfer->id, ['amount' => $left, 'metadata' => $tag + ['lg_trial_step' => 'cleanup']], ['idempotency_key' => "lg-trial-{$run}-cleanup-reversal"]));
        $r && note($created, 'transfer_reversal', $r->id, 'cleanup');
    }
}
if ($account !== null) {
    $deleted = soft('delete account', fn () => $stripe->accounts->delete($account->id));
    if ($deleted === null) {
        $closed = soft('close account (v2)', fn () => $stripe->v2->core->accounts->close($account->id, ['applied_configurations' => ['recipient']]));
        say('   account '.($closed ? 'closed' : 'NOT removed: delete it by hand in the Stripe dashboard'));
    } else {
        say('   account deleted');
    }
}

say('');
say('Final state of every object made:');
foreach ($created as [$kind, $id, $what]) {
    $state = soft("read {$id}", fn () => match ($kind) {
        'account' => 'see above',
        'payment_intent' => (function () use ($stripe, $id) {
            $pi = $stripe->paymentIntents->retrieve($id, ['expand' => ['latest_charge']]);

            return $pi->status.', refunded '.$pi->latest_charge->amount_refunded.' of '.$pi->amount;
        })(),
        'transfer' => (function () use ($stripe, $id) {
            $t = $stripe->transfers->retrieve($id);

            return 'reversed '.$t->amount_reversed.' of '.$t->amount.($t->reversed ? ' (fully reversed)' : '');
        })(),
        'refund' => $stripe->refunds->retrieve($id)->status,
        default => 'done',
    });
    say("  {$kind} {$id} ({$what}): {$state}");
}

say('');
say('Answers:');
foreach ($answers as $q => $a) {
    say("  {$q}: {$a}");
}
