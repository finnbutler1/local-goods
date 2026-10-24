<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Services\BidImpactMetrics;
use App\Services\DemoResetter;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The BID impact page (ADR 0017). */
class BidImpactPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_bid_and_platform_admins_and_judges_can_open_it_and_nobody_else(): void
    {
        foreach ([Role::BidAdmin, Role::PlatformAdmin, Role::Judge] as $role) {
            $this->signIn($role);
            $this->get('/bid-admin/impact')->assertOk();
        }

        foreach ([Role::Employee, Role::Merchant, Role::EmployerAdmin] as $role) {
            $this->signIn($role);
            $this->get('/bid-admin/impact')->assertForbidden();
        }

        auth()->logout();
        $this->get('/bid-admin/impact')->assertRedirect('/login');
    }

    public function test_it_is_in_the_bid_admin_menu_and_linked_from_the_platform_admin_dashboard(): void
    {
        $this->signIn(Role::BidAdmin);
        $this->get('/bid-admin')->assertSee(route('bid-admin.impact'));

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin')->assertSee(route('bid-admin.impact'));
    }

    public function test_the_page_shows_the_ledger_totals_and_explains_every_number(): void
    {
        $this->signIn(Role::BidAdmin);
        $total = (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->sum('amount_cents');
        $impact = app(BidImpactMetrics::class)->for();

        $response = $this->get('/bid-admin/impact')->assertOk()
            ->assertSee('Demo data: all people and transactions are fictional.')
            ->assertSee(Money::format($total).' since launch')
            ->assertSee(Money::format($impact['this_month']['spent_cents']))
            ->assertSee('Spent at independent merchants')
            ->assertSee('Merchants reached')
            ->assertSee('Employees participating')
            ->assertSee('Employers participating')
            ->assertSee('Employer engagement')
            ->assertSee('Meridian');

        // Four tiles, the trend and the employer rollup each say how they count.
        $this->assertSame(6, substr_count($response->getContent(), 'How we count this'));
    }

    public function test_the_recirculation_estimate_and_the_3x_line_are_labelled_as_an_outside_study(): void
    {
        $this->signIn(Role::BidAdmin);
        $estimate = BidImpactMetrics::recirculationCents(app(BidImpactMetrics::class)->totals()['spent_cents']);

        $html = $this->get('/bid-admin/impact')
            ->assertSee('Estimate from an outside study, not measured by Local Goods')
            ->assertSee("If the national study's rate held here, about", false)
            ->assertSee(Money::format($estimate))
            ->assertSee('Civic Economics')
            ->assertSee('Indie Impact Study Series: National Summary Report')
            ->assertSee('American Booksellers Association')
            ->assertSee('2011–2012')
            ->assertSee('134 businesses in 10 U.S. communities, none in DC')
            ->assertSee('52.3%')
            ->assertSee('15.8%')
            ->assertSee('https://www.publishersweekly.com/pw/by-topic/industry-news/bookselling/article/55547-news-from-aba-growing-shopping-local.html', false)
            ->assertSee('Read the Publishers Weekly report on the study')
            ->assertDontSee('bookweb.org', false)
            ->getContent();

        // The 3x line appears once, inside the labelled box with its source and the pilot line (ADR 0024).
        $box = str($html)->after('data-recirculation-estimate')->before('</section>')->toString();
        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($box)));
        foreach ([
            'Estimate from an outside study, not measured by Local Goods',
            'Money spent at independent local businesses tends to recirculate roughly 3x more in the local economy than money spent at chains, an effect often called the local multiplier.',
            'Real results will be measured in a pilot.',
            'Source: Civic Economics',
        ] as $line) {
            $this->assertStringContainsString($line, $text);
        }
        $this->assertSame(1, substr_count(strip_tags($html), 'roughly 3x'));
        $this->assertSame(1, substr_count(strip_tags($html), 'Estimate from an outside study, not measured by Local Goods'));
    }

    public function test_no_employee_is_named(): void
    {
        $this->signIn(Role::BidAdmin);
        $html = $this->get('/bid-admin/impact')->getContent();

        foreach (Employee::pluck('display_name') as $name) {
            $this->assertStringNotContainsString(e($name), $html);
        }
    }

    public function test_after_reset_demo_the_totals_still_equal_the_ledger(): void
    {
        app(DemoResetter::class)->reset();
        $this->signIn(Role::BidAdmin);

        $this->get('/bid-admin/impact')->assertOk()->assertSee(
            Money::format((int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->sum('amount_cents')).' since launch',
        );
    }

    public function test_an_empty_ledger_shows_the_empty_state(): void
    {
        foreach (['credit_ledger_entries', 'redemption_decisions', 'merchant_payouts', 'credit_allocations', 'credit_purchases', 'redemptions'] as $table) {
            DB::table($table)->delete();
        }
        $this->signIn(Role::BidAdmin);

        $this->get('/bid-admin/impact')->assertOk()
            ->assertSee('No cleared redemptions yet')
            ->assertDontSee('How we count this')
            ->assertDontSee('Estimate from an outside study');
    }
}
