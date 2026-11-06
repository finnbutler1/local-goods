<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Http\Middleware\TrustCloudFront;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\Feedback;
use App\Models\Merchant;
use App\Services\CreditLedger;
use App\Services\Payments\StripeCheckout;
use App\Services\Payments\StripeOnboarding;
use App\Services\ReviewerLinks;
use App\Support\AppUrl;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The move from d4ghrpm72uczx.cloudfront.net to uselocalgoods.com (ADR 0037).
 * CloudFront serves both and redirects the old one, but never /stripe/webhook.
 * Requests arrive the way CloudFront forwards them (ADR 0009): http to the
 * Beanstalk host, with the origin secret.
 */
class CustomDomainTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private const NEW_URL = 'https://uselocalgoods.com';

    private const OLD_URL = 'https://d4ghrpm72uczx.cloudfront.net';

    private const BEANSTALK = 'http://local-goods.example.elasticbeanstalk.com';

    private const SECRET = 'test-origin-secret-not-real';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();

        $this->app['env'] = 'production';
        config(['services.cloudfront.origin_secret' => self::SECRET]);
        // Laravel skips CSRF checks only in the "testing" environment; they aren't what's tested here.
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->useAppUrl(self::NEW_URL);
    }

    private function useAppUrl(string $url): void
    {
        config(['app.url' => $url]);
        AppUrl::force();
    }

    /** CloudFront doesn't pass the visitor's host on; $host stands in for one in case it ever does. */
    private function viaCloudFront(string $method, string $path, array $data = [], string $host = self::BEANSTALK): TestResponse
    {
        $server = $this->transformHeadersToServerVars([TrustCloudFront::SECRET_HEADER => self::SECRET]) + ['REMOTE_ADDR' => '130.176.1.10'];

        return $this->call($method, $host.$path, $data, server: $server);
    }

    public function test_the_previous_address_is_recorded(): void
    {
        $this->assertSame([self::OLD_URL], config('app.previous_urls'));
    }

    public function test_a_judge_link_made_before_the_switch_works_on_both_hosts(): void
    {
        $this->useAppUrl(self::OLD_URL);
        [$judge, $link] = app(ReviewerLinks::class)->invite('Casey Morgan', 'casey.judge@example.test', ReviewerType::ContestJudge);
        $this->assertStringStartsWith(self::OLD_URL.'/judge-access#', $link);

        $this->useAppUrl(self::NEW_URL);

        // The browser keeps the part after # through CloudFront's redirect; the page posts it.
        $secret = substr($link, strpos($link, '#') + 1);
        $this->assertTrue($judge->is(app(ReviewerLinks::class)->userFor($secret)));

        foreach ([self::BEANSTALK, self::OLD_URL, self::NEW_URL] as $host) {
            $this->viaCloudFront('GET', '/judge-access', host: $host)->assertOk()
                ->assertSee('action="'.self::NEW_URL.'/judge-access"', false);
            $this->viaCloudFront('POST', '/judge-access', ['link' => $secret], $host)->assertRedirect(self::NEW_URL.'/judge');
            $this->assertAuthenticatedAs($judge);
            auth()->logout();
        }
    }

    public function test_stripe_checkout_returns_to_the_new_domain(): void
    {
        $this->app->instance('request', Request::create(self::BEANSTALK.'/employer/purchases/1/checkout', 'POST'));
        app('url')->setRequest(app('request'));
        $purchase = app(CreditLedger::class)->requestPurchase(Employer::orderBy('id')->firstOrFail(), 10000, 'MPG-DOMAIN-1');

        app(StripeCheckout::class)->start($purchase);

        [$session] = $this->stripe->callsOf('checkout');
        $this->assertSame(self::NEW_URL.'/employer?checkout=returned&purchase=MPG-DOMAIN-1#buy', $session['return_url']);
        $this->assertStringNotContainsString('cloudfront', json_encode($session));
    }

    public function test_stripe_onboarding_returns_to_the_new_domain(): void
    {
        $this->app->instance('request', Request::create(self::BEANSTALK.'/merchant/stripe/onboard', 'POST'));
        app('url')->setRequest(app('request'));
        $merchant = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();

        app(StripeOnboarding::class)->link($merchant);

        [$link] = $this->stripe->callsOf('account_link');
        $this->assertSame([self::NEW_URL.'/merchant/stripe/refresh', self::NEW_URL.'/merchant/stripe/return'], [$link['refreshUrl'], $link['returnUrl']]);
    }

    public function test_webhooks_still_arrive_and_are_never_redirected(): void
    {
        $purchase = app(CreditLedger::class)->requestPurchase(Employer::orderBy('id')->firstOrFail(), 10000, 'MPG-DOMAIN-2');
        app(StripeCheckout::class)->start($purchase);
        $purchase->refresh();
        $intent = [
            'id' => 'pi_test_domain', 'object' => 'payment_intent', 'status' => 'succeeded',
            'amount' => 10000, 'amount_received' => 10000, 'currency' => 'usd',
            'latest_charge' => 'ch_test_domain', 'transfer_group' => $purchase->reference,
            'metadata' => ['lg_purchase_token' => $purchase->payment_token, 'lg_purchase_reference' => $purchase->reference],
        ];

        $this->postWebhook(
            $this->stripeEvent('payment_intent.succeeded', $intent),
            server: ['REMOTE_ADDR' => '130.176.1.10', 'HTTP_'.strtoupper(str_replace('-', '_', TrustCloudFront::SECRET_HEADER)) => self::SECRET],
            url: self::BEANSTALK.'/stripe/webhook',
        )->assertOk()->assertJson(['outcome' => 'paid: MPG-DOMAIN-2']);

        $this->assertSame(CreditPurchase::STATUS_PAID, $purchase->fresh()->status);
    }

    public function test_feedback_links_from_the_old_address_still_open(): void
    {
        $page = fn (string $url) => (new Feedback)->forceFill(['page_url' => $url])->safePageUrl();

        $this->assertSame(self::NEW_URL.'/bid-admin', $page(self::NEW_URL.'/bid-admin'));
        $this->assertSame(self::OLD_URL.'/employer?pay=1#buy', $page(self::OLD_URL.'/employer?pay=1#buy'));
        $this->assertSame(self::OLD_URL, $page(self::OLD_URL));
        $this->assertNull($page('https://uselocalgoods.com.example.org/login'));
        $this->assertNull($page('https://example.org/'.self::NEW_URL));
    }
}
