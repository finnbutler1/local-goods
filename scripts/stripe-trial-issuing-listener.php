<?php

/**
 * Local half of stripe-trial-issuing.php. Not part of the app; never run by
 * it, by CI or by a deploy. Answers issuing_authorization.request by rule, so
 * the trial can see whether Stripe obeys a reply forwarded through stripe
 * listen, and what happens after 2 seconds. Logs no card details.
 *
 * The rule applies only to a card with metadata lg_trial_rules=1; any other
 * card is approved, so the plain cases in the trial are not affected.
 */

require __DIR__.'/../vendor/autoload.php';

const INSIDE = ['1111111111'];
const SLOW = '4444444444';

$started = hrtime(true);
$payload = (string) file_get_contents('php://input');

try {
    $event = \Stripe\Webhook::constructEvent($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', (string) getenv('STRIPE_TRIAL_WEBHOOK_SECRET'));
} catch (\Throwable) {
    http_response_code(400);
    exit;
}
if ($event->type !== 'issuing_authorization.request') {
    http_response_code(200);
    exit;
}

$auth = $event->data->object;
$networkId = (string) ($auth->merchant_data->network_id ?? '');
$rulesApply = (string) ($auth->card->metadata->lg_trial_rules ?? '') === '1';
$approved = $rulesApply ? in_array($networkId, INSIDE, true) : true;
if ($rulesApply && $networkId === SLOW) {
    sleep(3); // Past Stripe's 2-second limit. The late answer is a decline, so an approval proves the timeout setting decided.
}

file_put_contents((string) getenv('STRIPE_TRIAL_LISTENER_LOG'), json_encode([
    'authorization' => $auth->id,
    'network_id' => $networkId,
    'rules_apply' => $rulesApply,
    'answered_approved' => $approved,
    'handler_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
])."\n", FILE_APPEND);

http_response_code(200);
header('Stripe-Version: '.\Stripe\Util\ApiVersion::CURRENT);
header('Content-Type: application/json');
echo json_encode(['approved' => $approved]);
