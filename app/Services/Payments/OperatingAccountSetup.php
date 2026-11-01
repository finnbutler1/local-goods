<?php

namespace App\Services\Payments;

use App\Models\OperatingAccount;
use App\Support\StripeEnvironment;
use App\Support\StripeMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Create the Local Goods Operating account (test mode)" (ADR 0034): the
 * connected account Local Goods sets up for its own money, one per
 * environment. The customer funds account is the Stripe platform balance;
 * this one receives only earned fees, by a recorded sweep.
 *
 * A v2 account with no Stripe dashboard, filled in with Stripe's test
 * values (like DemoMerchantStripeSetup, which is for merchants and never
 * this). No bank account is ever added, so Stripe never pays it out and its
 * balance shows every sweep; transfers are active without one (trial of
 * 2026-10-30). Pressing again creates nothing new: the record and its
 * idempotency key are made before Stripe is asked, and the id is saved as
 * soon as Stripe answers. Test mode only.
 */
class OperatingAccountSetup
{
    public const DISPLAY_NAME = 'Local Goods Operating (test mode)';

    public function __construct(private StripeGateway $stripe) {}

    public function create(string $ip): OperatingAccount
    {
        if (! StripeMode::active()) {
            throw new StripeGatewayException(StripeMode::problem().' Nothing was created.');
        }

        // One record per environment; the key is fixed before Stripe is asked, so a retry reuses it.
        $record = DB::transaction(fn () => OperatingAccount::lockForUpdate()->orderBy('id')->first()
            ?? OperatingAccount::create(['setup_key' => (string) Str::uuid()]));

        if ($record->stripe_account_id !== null) {
            return $this->refresh($record);
        }

        $account = $this->stripe->createOperatingAccount([
            'display_name' => self::DISPLAY_NAME,
            // A placeholder, like the demo merchants': v2 requires one, and nobody reads it in test mode.
            'contact_email' => 'operating@example.com',
            'identity' => [
                'country' => 'US',
                'entity_type' => 'individual',
                // Stripe's test values: they pass verification in test mode only.
                'individual' => [
                    'given_name' => 'Demo',
                    'surname' => 'Owner',
                    'date_of_birth' => ['day' => 1, 'month' => 1, 'year' => 1901],
                    'id_numbers' => [['type' => 'us_ssn', 'value' => '000000000']],
                    'address' => ['line1' => 'address_full_match', 'city' => 'Washington', 'state' => 'DC', 'postal_code' => '20006', 'country' => 'US'],
                    'phone' => '+12025550100',
                ],
                // The record's created_at, fixed with setup_key: a retry sends Stripe identical parameters.
                'attestations' => ['terms_of_service' => ['account' => ['date' => $record->created_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'), 'ip' => $ip]]],
            ],
            'defaults' => [
                'responsibilities' => ['fees_collector' => 'application', 'losses_collector' => 'application'],
                'profile' => ['product_description' => 'Local Goods\' own operating money in the test-mode demo. Never customer funds.'],
            ],
            'configuration' => ['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]],
            'metadata' => ['lg_operating_account' => '1'] + StripeEnvironment::tag(),
        ], 'lg-operating-account-'.$record->setup_key);

        $record->update(['stripe_account_id' => $account['id'], 'transfers_active' => self::transfersActive($account)]);

        return $record;
    }

    /** Reads the account's status from Stripe and saves whether it can receive transfers. */
    public function refresh(OperatingAccount $record): OperatingAccount
    {
        $record->update(['transfers_active' => self::transfersActive($this->stripe->retrieveAccount($record->stripe_account_id))]);

        return $record;
    }

    /** @param  array<string, mixed>  $account  a v2 Account */
    public static function transfersActive(array $account): bool
    {
        return ($account['configuration']['recipient']['capabilities']['stripe_balance']['stripe_transfers']['status'] ?? null) === 'active';
    }
}
