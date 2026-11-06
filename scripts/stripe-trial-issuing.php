<?php

/**
 * One-off trial of Stripe Issuing for a possible card preview. Not part of the
 * app; never run by it, by CI or by a deploy.
 *
 * Q1 is Issuing on, in the trial sandbox and in the existing test account?
 * Q2 how is the test Issuing balance funded?
 * Q3 can we create a cardholder and a virtual card?
 * Q4 physical cards: can we create one and simulate shipping?
 * Q5 does a simulated authorization keep our merchant data?
 * Q6 what can spending controls decline, and what can they not?
 * Q7 does a real-time reply through stripe listen decide the authorization,
 *    how long does it take, and what happens past 2 seconds?
 * Q8 do any of these steps take a fee from the platform balance?
 *
 * Safety: plain PHP with the Stripe SDK, no Laravel, no database. Test secret
 * keys from the environment only, never printed. Everything is made in a
 * throwaway sandbox; the existing account gets two read-only calls and the
 * script refuses to run if both keys reach one account. All people and
 * merchants are made up. Never asks for a card number or CVC. Cards are
 * cancelled and cardholders deactivated at the end; Stripe has no delete for
 * these, so deleting the sandbox is the last step of the cleanup.
 *
 * Run with scripts/stripe-trial-issuing-listener.php behind stripe listen.
 * Needs STRIPE_TRIAL_SECRET, STRIPE_TRIAL_EXISTING_SECRET and
 * STRIPE_TRIAL_LISTENER_LOG.
 */

require __DIR__.'/../vendor/autoload.php';

use Stripe\StripeClient;

$key = (string) getenv('STRIPE_TRIAL_SECRET');
$existingKey = (string) getenv('STRIPE_TRIAL_EXISTING_SECRET');
$listenerLog = (string) getenv('STRIPE_TRIAL_LISTENER_LOG');
if (! str_starts_with($key, 'sk_test_') || ! str_starts_with($existingKey, 'sk_test_')) {
    fwrite(STDERR, "Refusing to run: both keys must be Stripe test secret keys (sk_test_...). Live keys are never used.\n");
    exit(1);
}

$stripe = new StripeClient(['api_key' => $key, 'max_network_retries' => 1]);
$existing = new StripeClient(['api_key' => $existingKey, 'max_network_retries' => 1]);
if ($stripe->accounts->retrieve()->id === $existing->accounts->retrieve()->id) {
    fwrite(STDERR, "Refusing to run: the trial key reaches the existing account. Use a key from the new sandbox.\n");
    exit(1);
}

$run = 'issuing-'.date('Ymd-His');
$tag = ['lg_env' => 'scratch', 'lg_instance' => 'issuing-trial', 'lg_trial' => '1', 'lg_trial_run' => $run];
$started = time() - 5;
$fixtures = __DIR__.'/../tests/Fixtures/stripe/issuing';
$created = $answers = $cards = $holders = $auths = $saved = [];
$design = null;

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
function balances(StripeClient $stripe): array
{
    $b = $stripe->balance->retrieve()->toArray();

    return ['available' => usd($b['available'] ?? []), 'pending' => usd($b['pending'] ?? []), 'issuing' => usd($b['issuing']['available'] ?? [])];
}
function merchant(string $name, string $networkId, string $postal, string $city = 'Washington', string $state = 'DC', string $category = 'eating_places_restaurants'): array
{
    return ['name' => $name, 'network_id' => $networkId, 'postal_code' => $postal, 'city' => $city, 'state' => $state, 'country' => 'US', 'category' => $category];
}

$inside = merchant('Farragut Grind', '1111111111', '20006');
$outside = merchant('Scratch Outside Diner', '2222222222', '22201', 'Arlington', 'VA');
$sameZip = merchant('Scratch Same ZIP Not Listed', '3333333333', '20006');
$slow = merchant('Scratch Slow Answer', '4444444444', '20006');

$swipe = function (string $card, array $m, int $cents) use ($stripe, &$auths, &$saved): ?\Stripe\Issuing\Authorization {
    $t = hrtime(true);
    $a = soft('authorization at '.$m['name'], fn () => $stripe->testHelpers->issuing->authorizations->create([
        'card' => $card, 'amount' => $cents, 'authorization_method' => 'chip', 'merchant_data' => $m,
    ]));
    if ($a === null) { return null; }
    $ms = (int) ((hrtime(true) - $t) / 1_000_000);
    $auths[] = $a;
    $saved['authorization'] ??= $a->toArray();
    $h = $a->request_history[0] ?? null;
    say(sprintf('  %s $%.2f: %s, status %s, reason %s%s, call took %d ms', $m['name'], $cents / 100, $a->approved ? 'APPROVED' : 'DECLINED', $a->status, $h->reason ?? 'none', isset($h->reason_message) ? ' ('.$h->reason_message.')' : '', $ms));

    return $a;
};
$makeCard = function (string $holder, array $extra, string $what) use ($stripe, $tag, $run, &$created, &$cards) {
    $c = $stripe->issuing->cards->create($extra + ['cardholder' => $holder, 'currency' => 'usd', 'status' => 'active', 'metadata' => $tag], ['idempotency_key' => "lg-trial-{$run}-card-".count($cards)]);
    $cards[] = $c;
    note($created, 'card', $c->id, $what.', status '.$c->status);

    return $c;
};

try {
    say('0. Waiting for the listener to connect');
    sleep(10);

    say('1. Is Issuing on?');
    try {
        $existing->issuing->cardholders->all(['limit' => 1]);
        $answers['Q1 existing test account'] = 'on (read-only check, nothing created)';
    } catch (\Stripe\Exception\ApiErrorException $e) {
        $answers['Q1 existing test account'] = 'NOT usable: '.stripeError($e);
    }
    try {
        $stripe->issuing->cardholders->all(['limit' => 1]);
        $answers['Q1 trial sandbox'] = 'on';
    } catch (\Stripe\Exception\ApiErrorException $e) {
        $answers['Q1 trial sandbox'] = 'NOT usable: '.stripeError($e);
        throw new RuntimeException('Issuing is not on in the trial sandbox. Stopping; nothing was created.');
    }
    $before = balances($stripe);
    say('   balance at the start (cents): '.json_encode($before));

    say('2. Fund the test Issuing balance with $50.00');
    $topUp = ['amount' => 5000, 'currency' => 'usd', 'destination_balance' => 'issuing', 'description' => 'Local Goods scratch trial', 'statement_descriptor' => 'LG trial', 'metadata' => $tag];
    $how = null;
    foreach (['top-up' => [], 'top-up from a test bank token' => ['source' => 'btok_us_verified']] as $label => $extra) {
        try {
            $t = $stripe->topups->create($topUp + $extra, ['idempotency_key' => "lg-trial-{$run}-topup-".md5($label)]);
            note($created, 'topup', $t->id, $label.', status '.$t->status);
            $saved['topup'] = $t->toArray();
            $how = $label;
            break;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            say("  {$label} refused: ".stripeError($e));
        }
    }
    if ($how === null) {
        try {
            $stripe->rawRequest('post', '/v1/test_helpers/issuing/fund_balance', ['amount' => 5000, 'currency' => 'usd']);
            $how = 'fund_balance test helper';
        } catch (\Stripe\Exception\ApiErrorException $e) {
            say('  fund_balance refused: '.stripeError($e));
        }
    }
    for ($i = 0; $i < 10 && balances($stripe)['issuing'] < 5000; $i++) { sleep(3); }
    $funded = balances($stripe)['issuing'];
    $answers['Q2 funding'] = ($how ?? 'every API method refused').', Issuing balance now '.$funded.' cents';
    if ($funded < 5000) {
        throw new RuntimeException('The Issuing balance did not fund. Stopping: try Add funds in the sandbox Dashboard.');
    }

    say('3. Cardholder and virtual card');
    $holder = $stripe->issuing->cardholders->create([
        'name' => 'Demo Owner', 'email' => 'scratch-trial@example.com', 'phone_number' => '+12025550100', 'status' => 'active', 'type' => 'individual',
        'individual' => [
            'first_name' => 'Demo', 'last_name' => 'Owner', 'dob' => ['day' => 1, 'month' => 1, 'year' => 1990],
            'card_issuing' => ['user_terms_acceptance' => ['date' => time(), 'ip' => '127.0.0.1']],
        ],
        'billing' => ['address' => ['line1' => '1 Demo Street', 'city' => 'Washington', 'state' => 'DC', 'postal_code' => '20006', 'country' => 'US']],
        'metadata' => $tag,
    ], ['idempotency_key' => "lg-trial-{$run}-holder"]);
    $holders[] = $holder;
    note($created, 'cardholder', $holder->id, 'status '.$holder->status);
    $saved['cardholder'] = $holder->toArray();
    $virtual = $makeCard($holder->id, ['type' => 'virtual'], 'virtual');
    $saved['card-virtual'] = $virtual->toArray();
    $answers['Q3 virtual card'] = 'status '.$virtual->status.', brand '.$virtual->brand.', past-due requirements: '.json_encode($holder->requirements->past_due ?? []);

    say('4. Physical cards and simulated shipping');
    $bundle = null;
    foreach ($stripe->issuing->physicalBundles->all(['limit' => 20])->data as $b) {
        say('   bundle '.$b->name.': type '.$b->type.', status '.$b->status);
        if ($bundle === null && $b->status === 'active' && $b->type === 'standard') { $bundle = $b; }
    }
    if ($bundle === null) {
        $answers['Q4 physical'] = 'no active standard bundle in this sandbox';
    } else {
        $design = $stripe->issuing->personalizationDesigns->create(['physical_bundle' => $bundle->id, 'name' => 'Local Goods scratch trial', 'metadata' => $tag]);
        note($created, 'personalization_design', $design->id, 'status '.$design->status);
        if ($design->status !== 'active') {
            $design = $stripe->testHelpers->issuing->personalizationDesigns->activate($design->id);
            say('   design after the activate helper: '.$design->status);
        }
        $shipping = ['name' => 'Demo Owner', 'service' => 'standard', 'type' => 'individual', 'address' => ['line1' => '1 Demo Street', 'city' => 'Washington', 'state' => 'DC', 'postal_code' => '20006', 'country' => 'US']];
        $steps = ['delivered' => ['submitCard', 'shipCard', 'deliverCard'], 'returned' => ['submitCard', 'shipCard', 'returnCard']];
        $results = [];
        foreach ($steps as $ending => $helpers) {
            $c = $makeCard($holder->id, ['type' => 'physical', 'personalization_design' => $design->id, 'shipping' => $shipping], 'physical, to be '.$ending);
            $trail = [$c->shipping->status ?? 'no shipping status'];
            foreach ($helpers as $helper) {
                $c = soft($helper, fn () => $stripe->testHelpers->issuing->cards->{$helper}($c->id)) ?? $c;
                $trail[] = $c->shipping->status ?? 'no shipping status';
            }
            $results[] = $ending.' path: '.implode(' -> ', $trail);
            $saved['card-physical'] = $c->toArray();
            if ($ending === 'delivered') {
                say('   one swipe on the delivered card');
                $swipe($c->id, $inside, 300);
            }
        }
        $answers['Q4 physical'] = implode('; ', $results);
    }

    say('5. Authorization with merchant data; no rules on this card');
    $a1 = $swipe($virtual->id, $inside, 500);
    $a2 = $swipe($virtual->id, $outside, 500);
    if ($a1) {
        $md = $a1->merchant_data;
        $answers['Q5 merchant data kept'] = "{$md->name} / {$md->category} / {$md->postal_code} / {$md->network_id}";
    }
    if ($a2) {
        $answers['Q5 outside merchant with no rule'] = $a2->approved ? 'APPROVED: Stripe alone does not know the district' : 'declined';
    }
    if ($a1 && $a1->approved) {
        $tx = soft('capture', fn () => $stripe->testHelpers->issuing->authorizations->capture($a1->id));
        say('   captured; Issuing balance now '.balances($stripe)['issuing'].' cents. A captured transaction stays in the sandbox.');
        $answers['Q5 capture'] = $tx ? 'status '.$tx->status : 'failed';
    }

    say('6. Spending controls');
    $controlled = $makeCard($holder->id, ['type' => 'virtual', 'spending_controls' => [
        'allowed_categories' => ['eating_places_restaurants'],
        'spending_limits' => [['amount' => 2000, 'interval' => 'per_authorization']],
    ]], 'virtual, restaurants only, $20 limit');
    $allowed = $swipe($controlled->id, $inside, 500);
    $otherCategory = $swipe($controlled->id, merchant('Scratch Book Shop', '5555555555', '20006', 'Washington', 'DC', 'book_stores'), 500);
    $overLimit = $swipe($controlled->id, $inside, 2500);
    $broke = $swipe($virtual->id, $inside, 9000);
    $line = fn ($a) => $a ? ($a->approved ? 'approved' : 'declined: '.($a->request_history[0]->reason ?? 'no reason')) : 'failed';
    $answers['Q6 allowed category'] = $line($allowed);
    $answers['Q6 other category'] = $line($otherCategory);
    $answers['Q6 over the per-swipe limit'] = $line($overLimit);
    $answers['Q6 more than the Issuing balance'] = $line($broke);
    $answers['Q6 by merchant or postal code'] = 'no such setting in the public docs; merchant ID is private preview, so Q7 is the only route';

    say('7. Real-time decisions through stripe listen');
    is_file($listenerLog) && unlink($listenerLog);
    $ruled = $makeCard($holder->id, ['type' => 'virtual', 'metadata' => $tag + ['lg_trial_rules' => '1']], 'virtual, rule card');
    $expected = [
        'inside, on the list' => [$inside, true],
        'outside, other ZIP' => [$outside, false],
        'outside, same ZIP' => [$sameZip, false],
        'reply takes 3 seconds' => [$slow, false],
    ];
    foreach ($expected as $case => [$m, $wanted]) {
        say("   {$case}");
        $a = $swipe($ruled->id, $m, 500);
        sleep(4); // Lets a slow reply finish before the next one; the local server handles one at a time.
        $logged = null;
        foreach (is_file($listenerLog) ? file($listenerLog) : [] as $row) {
            $row = json_decode($row, true);
            if ($a && ($row['authorization'] ?? null) === $a->id) { $logged = $row; }
        }
        if ($a === null) { $answers["Q7 {$case}"] = 'authorization failed'; continue; }
        $reason = $a->request_history[0]->reason ?? 'none';
        $answers["Q7 {$case}"] = $logged === null
            ? 'listener never got it; Stripe said '.($a->approved ? 'approved' : 'declined').', reason '.$reason
            : 'listener answered '.($logged['answered_approved'] ? 'approve' : 'decline').' in '.$logged['handler_ms'].' ms; Stripe said '.($a->approved ? 'approved' : 'declined').', reason '.$reason.'; '.($a->approved === $wanted ? 'as the rule wanted' : 'NOT what the rule wanted');
    }

    say('8. Did the platform balance move?');
    sleep(5);
    $after = balances($stripe);
    $answers['Q8 balance (cents)'] = 'before '.json_encode($before).', after '.json_encode($after);
    $types = [];
    foreach ($stripe->balanceTransactions->all(['created' => ['gte' => $started], 'limit' => 100])->autoPagingIterator() as $bt) {
        $k = $bt->type.'/'.$bt->reporting_category;
        $types[$k] = ($types[$k] ?? 0) + $bt->amount;
        $bt->fee === 0 || $types['fee on '.$k] = ($types['fee on '.$k] ?? 0) + $bt->fee;
    }
    $answers['Q8 balance transactions by type (cents)'] = $types ? json_encode($types) : 'none';
} catch (Throwable $e) {
    $answers['TRIAL STOPPED'] = ($e instanceof \Stripe\Exception\ApiErrorException ? stripeError($e) : get_class($e).': '.$e->getMessage()).'. Cleanup below still ran.';
}

say('9. Fixtures (scrubbed)');
sleep(5);
foreach (soft('list events', fn () => $stripe->events->all(['created' => ['gte' => $started], 'limit' => 100])->autoPagingIterator()) ?? [] as $event) {
    if (str_starts_with($event->type, 'issuing_') || str_starts_with($event->type, 'topup.')) {
        $saved['event-'.str_replace(['.', '_'], '-', $event->type)] ??= $event->toArray();
    }
}
$map = [];
$remove = ['client_secret', 'email', 'phone_number', 'phone', 'url', 'ip', 'fingerprint', 'last4', 'exp_month', 'exp_year', 'number', 'cvc', 'line1', 'authorization_code', 'network_transaction_id', 'transaction_id', 'tracking_number', 'tracking_url', 'idempotency_key', 'dob', 'date'];
$scrub = function ($value) use (&$scrub, &$map, $remove) {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            if (in_array($k, $remove, true)) { $out[$k] = $v === null ? null : 'fake-removed'; continue; }
            if ($k === 'lg_trial_run') { $out[$k] = 'fake-run'; continue; }
            $out[$k] = $scrub($v);
        }

        return $out;
    }
    if (is_string($value)) {
        return preg_replace_callback('/\b([a-z]{2,6})_(?=[a-z]*[0-9A-Z])[A-Za-z0-9]{8,}\b/', fn ($m) => str_starts_with($m[0], 'fake_') ? $m[0] : ($map[$m[0]] ??= 'fake_'.$m[1].'_'.(count($map) + 1)), $value);
    }

    return $value;
};
$clean = [];
foreach ($saved as $name => $body) {
    $clean[$name] = json_encode($scrub($body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    // Same checks as StripeTrialFixturesTest; a fixture that fails is not saved at all.
    if (preg_match('/(?<![A-Za-z0-9_])(?!fake_)[a-z]{2,6}_(?=[a-z]*[0-9A-Z])[A-Za-z0-9]{8,}/', $clean[$name]) || str_contains($clean[$name], '@') || preg_match('/\b\d{13,19}\b/', $clean[$name])) {
        say("   not saving {$name}: it failed the scrub check");
        unset($clean[$name]);
    }
}
is_dir($fixtures) || mkdir($fixtures, 0755, true);
foreach ($clean as $name => $text) { file_put_contents("{$fixtures}/{$name}.json", $text); }
say('   saved '.count($clean).' scrubbed fixtures in tests/Fixtures/stripe/issuing/');

say('10. Cleanup');
foreach ($auths as $a) {
    $fresh = soft('read '.$a->id, fn () => $stripe->issuing->authorizations->retrieve($a->id));
    if ($fresh && $fresh->status === 'pending') {
        soft('reverse '.$a->id, fn () => $stripe->testHelpers->issuing->authorizations->reverse($a->id));
    }
}
foreach ($cards as $c) {
    $done = soft('cancel '.$c->id, fn () => $stripe->issuing->cards->update($c->id, ['status' => 'canceled']));
    say('   card '.$c->id.': '.($done->status ?? 'NOT cancelled'));
}
foreach ($holders as $h) {
    $done = soft('deactivate '.$h->id, fn () => $stripe->issuing->cardholders->update($h->id, ['status' => 'inactive']));
    say('   cardholder '.$h->id.': '.($done->status ?? 'NOT deactivated'));
}
if ($design !== null) {
    $done = soft('deactivate design', fn () => $stripe->testHelpers->issuing->personalizationDesigns->deactivate($design->id));
    say('   design '.$design->id.': '.($done->status ?? 'NOT deactivated'));
}
$left = balances($stripe)['issuing'];
if ($left > 0) {
    $p = soft('pay out the Issuing balance', fn () => $stripe->payouts->create(['amount' => $left, 'currency' => 'usd', 'source_balance' => 'issuing', 'metadata' => $tag]));
    say('   Issuing balance of '.$left.' cents: '.($p ? 'payout '.$p->id.', status '.$p->status : 'still there (test money only)'));
}
say('   Stripe has no delete for cardholders, cards, authorizations or transactions: delete the sandbox to remove them.');

say('');
say('Every object made:');
foreach ($created as [$kind, $id, $what]) { say("  {$kind} {$id}: {$what}"); }
say('');
say('Answers:');
foreach ($answers as $q => $a) { say("  {$q}: {$a}"); }
