<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Services\CreditLedger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\Support\AccessibilityAudit;
use Tests\TestCase;

/**
 * What the employer sees of the employer platform fee (ADR 0042). Off (the
 * default): only the display-only "Planned platform fee (not charged during
 * the pilot)" box, labelled as a projection, linking to the business model
 * for viewers who may open it. On: the fee on the buy form, Checkout's
 * breakdown, the purchase row and the confirmation, and that it can't be
 * refunded in this version.
 */
class EmployerPlatformFeeDisplayTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    private const PREVIEW = 'data-planned-fee-preview';

    private const NOT_REFUNDABLE = 'The platform fee is Local Goods&#039; own revenue, not credits, and can&#039;t be refunded in this version.';

    private Employer $employer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->employer = Employer::firstOrFail();
        foreach ($this->employer->employees()->orderBy('id')->take(3)->get() as $employee) {
            app(CreditLedger::class)->allocate($this->employer, $employee, 100, Carbon::parse('2027-02-10 12:00', 'America/New_York')->utc());
        }
        $this->travelTo(Carbon::parse('2027-03-10 12:00', 'America/New_York'));
        config(['reviewers.judging_ends_at' => '2027-12-31 23:59']);
    }

    // --- Off ---------------------------------------------------------------------

    public function test_off_the_employer_sees_the_labelled_preview_without_a_link_it_could_not_open(): void
    {
        $this->signIn(Role::EmployerAdmin);
        $page = $this->get('/employer')->assertOk()
            ->assertSee(self::PREVIEW, false)
            ->assertSee('Planned platform fee (not charged during the pilot)')
            ->assertSee('Projection, not results')
            ->assertSee('$6.00')
            ->assertSee('Last month 3 employees received credits here, so it would come to')
            ->assertSee('$18.00')
            ->assertSee('Nothing is charged now.')
            ->assertSee("business model's stated assumptions.", false)
            ->assertDontSee('/business-model#assumptions')
            ->assertDontSee('data-employer-fee-next', false);
        $this->assertSame([], AccessibilityAudit::problems($page->getContent()));

        $this->get(route('business-model'))->assertForbidden();
    }

    public function test_off_viewers_who_may_open_the_business_model_get_the_link(): void
    {
        $this->signIn(Role::Judge);
        $this->get('/employer')->assertOk()->assertSee(self::PREVIEW, false)
            ->assertSee('href="'.route('business-model').'#assumptions"', false);
        $this->get(route('business-model'))->assertOk()->assertSee('id="assumptions"', false);
    }

    public function test_off_with_nobody_last_month_the_preview_shows_only_the_price(): void
    {
        $this->travelTo(Carbon::parse('2027-06-10 12:00', 'America/New_York'));
        $this->signIn(Role::EmployerAdmin);

        $this->get('/employer')->assertOk()->assertSee(self::PREVIEW, false)->assertDontSee('Last month');
    }

    public function test_off_a_purchase_row_and_its_checkout_say_nothing_about_the_fee(): void
    {
        $this->fakeStripe();
        $this->signIn(Role::EmployerAdmin);
        $this->post('/employer/purchases', ['purchase_amount' => '400']);
        $purchase = CreditPurchase::latest('id')->firstOrFail();

        $this->get('/employer?pay='.$purchase->reference)->assertOk()
            ->assertDontSee('data-fee-breakdown', false)->assertDontSee('data-employer-fee-refunds', false);
        $this->get('/employer#credits')->assertOk()->assertDontSee('data-employer-fee-row', false);
    }

    // --- On ----------------------------------------------------------------------

    public function test_on_the_buy_form_says_whether_this_purchase_adds_the_fee(): void
    {
        config(['payments.employer_platform_fee_enabled' => true]);
        $this->fakeStripe();
        $this->signIn(Role::EmployerAdmin);

        $form = $this->get('/employer')->assertOk()->assertDontSee(self::PREVIEW, false)
            ->assertSee('data-employer-fee-next="adds"', false)
            ->assertSee('This purchase adds the platform fee for March 2027:')
            ->assertSee('3 participating employees × $6.00 = $18.00.')
            ->assertSee(self::NOT_REFUNDABLE, false);
        $this->assertSame([], AccessibilityAudit::problems($form->getContent()));

        $this->post('/employer/purchases', ['purchase_amount' => '400']);
        $purchase = CreditPurchase::latest('id')->firstOrFail();

        // Paying: the breakdown and the refund sentence, before the card form.
        $this->get('/employer?pay='.$purchase->reference)->assertOk()
            ->assertSee('Credits $400.00 + platform fee $18.00 (Platform fee for March 2027: 3 participating employees × $6.00) = total $418.00')
            ->assertSee('data-employer-fee-refunds', false)
            ->assertSee(self::NOT_REFUNDABLE, false);

        // This month's fee is taken by that purchase, so the next one adds none.
        $this->get('/employer')->assertOk()->assertSee('data-employer-fee-next="none"', false)
            ->assertSee('No platform fee on this purchase');
        $this->get('/employer')->assertSee('+ $18.00 Platform fee for March 2027: 3 participating employees × $6.00 · not refundable');
    }

    public function test_on_the_confirmation_names_the_fee_and_its_month(): void
    {
        config(['payments.employer_platform_fee_enabled' => true]);
        $this->signIn(Role::EmployerAdmin);

        $this->post('/employer/purchases', ['purchase_amount' => '400'])->assertRedirect();

        $toast = html_entity_decode((string) session('status'), ENT_QUOTES);
        $this->assertStringContainsString('$400.00 in credits added (simulated, no real money, ', $toast);
        $this->assertStringContainsString('; $418.00 paid, including a $18.00 platform fee for March 2027). Ready to give.', $toast);
    }
}
