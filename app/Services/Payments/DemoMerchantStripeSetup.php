<?php

namespace App\Services\Payments;

use App\Models\Merchant;
use App\Services\Eligibility\MerchantCandidates;
use App\Support\StripeEnvironment;
use App\Support\StripeMode;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Connect demo merchants to Stripe (test mode)": gives every fictional demo
 * merchant with no login a Stripe test account, so a cleared redemption at
 * any of them sends a real test transfer.
 *
 * Stripe refuses to let a platform accept its terms for an Express account
 * ("tos_acceptance_on_behalf_not_allowed"), so these are v2 accounts with
 * no Stripe dashboard, filled in by Local Goods with Stripe's test values,
 * plus Stripe's test bank account. They are marked stripe_set_up_by_platform
 * and labelled "Demo account set up by Local Goods in test mode".
 *
 * Never for a merchant with a login (they onboard themselves through
 * Stripe's Express pages), never for a real business: only the names in
 * DatabaseSeeder::DEMO_MERCHANTS, and never a name that is also a real
 * candidate in data/merchant_candidates.csv. One merchant per request
 * (step()); running it again creates nothing new. Test mode only:
 * StripeMode refuses live keys.
 */
class DemoMerchantStripeSetup
{
    public function __construct(
        private StripeGateway $stripe,
        private StripeOnboarding $onboarding,
    ) {}

    /**
     * Does the work for one merchant, so no request waits long on Stripe
     * (CloudFront gives the origin 30 seconds): create its account and save
     * the id at once, then add the test bank account. Status (payouts turning
     * on) arrives later through account.updated. Every Stripe call reuses
     * the merchant's idempotency keys, so a request cut off midway is
     * finished by the next press with no duplicate account.
     *
     * @return array{merchant: ?string, done: int, total: int} the merchant worked on (null if all were done)
     */
    public function step(string $ip): array
    {
        if (! StripeMode::active()) {
            throw new StripeGatewayException(StripeMode::problem().' Nothing was created.');
        }

        $merchant = $this->pending()->first();

        if ($merchant !== null) {
            try {
                if ($merchant->stripe_account_id === null) {
                    $this->create($merchant, $ip);
                }
                $this->addBankAccount($merchant);
            } catch (StripeGatewayException $e) {
                throw new StripeGatewayException($merchant->name.': '.$e->getMessage(), $e->definite, $e->stripeCode);
            }
        }

        return ['merchant' => $merchant?->name] + $this->progress();
    }

    /** @return array{done: int, total: int} demo merchants with no login, and how many have a Stripe account set up */
    public function progress(): array
    {
        $total = self::demoMerchants()->whereNull('user_id')->count();

        return ['done' => $total - $this->pending()->count(), 'total' => $total];
    }

    /**
     * Demo merchants with no login still to set up, in order: no account
     * yet, or an account this action made that still needs its bank
     * account (a request cut off midway). Payouts may still be turning on;
     * that is Stripe's part and arrives through account.updated.
     *
     * @return Collection<int, Merchant>
     */
    public function pending(): Collection
    {
        return self::demoMerchants()->whereNull('user_id')
            ->filter(fn (Merchant $m) => $m->stripe_account_id === null
                || ($m->stripe_set_up_by_platform && ! $m->stripe_details_submitted && ! $m->stripe_payouts_enabled))
            ->values();
    }

    /**
     * The fictional demo merchants, by name. Refuses to run (throws) if the
     * real candidate list can't be read, so it can't fail open.
     *
     * @return Collection<int, Merchant>
     */
    public static function demoMerchants(): Collection
    {
        $realNames = collect(MerchantCandidates::load())->map(fn (array $c) => Str::lower(trim($c['name'])));

        return Merchant::whereIn('name', array_column(DatabaseSeeder::DEMO_MERCHANTS, 0))->orderBy('id')->get()
            ->reject(fn (Merchant $m) => $realNames->contains(Str::lower(trim($m->name))))
            ->values();
    }

    private function create(Merchant $merchant, string $ip): void
    {
        // Marked before Stripe is asked, so an account made by the platform
        // can never be shown as one the merchant set up itself.
        $merchant->stripe_onboarding_key ??= (string) Str::uuid();
        $merchant->forceFill(['stripe_set_up_by_platform' => true])->save();

        $account = $this->stripe->createDemoAccount([
            'display_name' => $merchant->name,
            'contact_email' => $merchant->stripeContactEmail(),
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
                    'email' => $merchant->stripeContactEmail(),
                    'phone' => '+12025550100',
                ],
                'attestations' => ['terms_of_service' => ['account' => ['date' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'ip' => $ip]]],
            ],
            'defaults' => [
                'responsibilities' => ['fees_collector' => 'application', 'losses_collector' => 'application'],
                'profile' => ['product_description' => 'Fictional '.$merchant->category.' in the Local Goods test-mode demo.'],
            ],
            'configuration' => ['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]],
            'metadata' => ['lg_merchant' => $merchant->name, 'lg_merchant_id' => (string) $merchant->id, 'lg_demo_setup' => '1'] + StripeEnvironment::tag(),
        ], 'lg-demo-account-v2-'.$merchant->stripe_onboarding_key);

        // Saved straight away, so a cut-off request never loses the id.
        $this->onboarding->apply($merchant, $account);
    }

    /** Stripe's test bank account (same key every time), then the status Stripe reports right now; no waiting. */
    private function addBankAccount(Merchant $merchant): void
    {
        $this->stripe->addTestBankAccount($merchant->stripe_account_id, 'lg-demo-bank-'.$merchant->stripe_onboarding_key);
        $this->onboarding->refresh($merchant);
    }
}
