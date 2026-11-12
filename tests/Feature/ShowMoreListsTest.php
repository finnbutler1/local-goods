<?php

namespace Tests\Feature;

use App\Enums\Occasion;
use App\Enums\Role;
use App\Models\CreditAllocation;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Feedback;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\User;
use App\Support\ShowMore;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PlacesMerchantsInTheDistrict;
use Tests\TestCase;

/**
 * Long lists show their newest few rows and a "Show all (N)" button that
 * reveals the rest in place (App\Support\ShowMore, <x-show-more>). Every row
 * is in the page (up to ShowMore::CAP), so without JavaScript nothing is
 * hidden; a list longer than the cap keeps its reload link. Rows that need a
 * decision are never folded away.
 */
class ShowMoreListsTest extends TestCase
{
    use PlacesMerchantsInTheDistrict;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** @return array{rows: int, folded: int} children of the list element with this id, and how many are folded away */
    private function list(string $html, string $id): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $list = $dom->getElementById($id);
        $this->assertNotNull($list, "No element with id {$id}");

        $rows = array_values(array_filter(iterator_to_array($list->childNodes), fn ($n) => $n instanceof DOMElement));

        return ['rows' => count($rows), 'folded' => count(array_filter($rows, fn (DOMElement $r) => $r->hasAttribute('data-more')))];
    }

    private function assertButton(string $html, string $id, string $label): void
    {
        $this->assertStringContainsString('data-show-more="'.$id.'"', $html);
        $this->assertStringContainsString('aria-controls="'.$id.'"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('>'.$label.'</button>', $html);
        $this->assertStringContainsString('id="'.$id.'"', $html, 'aria-controls must point at a real element');
    }

    private function assertNoButton(string $html, string $id): void
    {
        $this->assertStringNotContainsString('data-show-more="'.$id.'"', $html);
    }

    /** A merchant login with a business of its own and no payouts. */
    private function newMerchant(): Merchant
    {
        $owner = User::factory()->role(Role::Merchant)->create();
        $merchant = Merchant::create(['bid_id' => Merchant::where('name', 'Farragut Grind')->sole()->bid_id, 'name' => 'Test Bakery', 'category' => 'cafe', 'address' => '1 Test St NW', 'user_id' => $owner->id, ...$this->inTheDistrict()]);
        $this->actingAs($owner);

        return $merchant;
    }

    private function payouts(Merchant $merchant, int $upTo): void
    {
        while ($merchant->payouts()->count() < $upTo) {
            MerchantPayout::create(['merchant_id' => $merchant->id, 'amount_cents' => 100, 'status' => MerchantPayout::STATUS_SIMULATED, 'paid_at' => now()]);
        }
    }

    public function test_payout_history_shows_three_and_reveals_the_rest(): void
    {
        $this->signIn(Role::Merchant);
        $merchant = Merchant::where('name', 'Farragut Grind')->sole();
        $this->payouts($merchant, 20);

        $html = $this->get('/merchant')->assertOk()->getContent();
        $this->assertSame(['rows' => 20, 'folded' => 17], $this->list($html, 'payout-rows'));
        $this->assertButton($html, 'payout-rows', 'Show all payouts (20)');
        $this->assertStringNotContainsString('all_payouts=1', $html);
    }

    public function test_three_or_fewer_payouts_have_no_button(): void
    {
        $merchant = $this->newMerchant();
        $this->payouts($merchant, 3);

        $html = $this->get('/merchant')->assertOk()->getContent();
        $this->assertSame(['rows' => 3, 'folded' => 0], $this->list($html, 'payout-rows'));
        $this->assertNoButton($html, 'payout-rows');
    }

    public function test_payouts_over_the_cap_load_fifty_and_keep_a_reload_link(): void
    {
        $this->signIn(Role::Merchant);
        $merchant = Merchant::where('name', 'Farragut Grind')->sole();
        $this->payouts($merchant, ShowMore::CAP + 5);
        $total = ShowMore::CAP + 5;

        $html = $this->get('/merchant')->assertOk()->getContent();
        $this->assertSame(['rows' => ShowMore::CAP, 'folded' => ShowMore::CAP - ShowMore::PAYOUTS], $this->list($html, 'payout-rows'));
        $this->assertButton($html, 'payout-rows', 'Show all payouts ('.ShowMore::CAP.')');
        $this->assertStringContainsString('all_payouts=1', $html);
        $this->assertStringContainsString("Show all {$total} payouts", $html);

        $html = $this->get('/merchant?all_payouts=1')->assertOk()->getContent();
        $this->assertSame($total, $this->list($html, 'payout-rows')['rows']);
        $this->assertStringNotContainsString("Show all {$total} payouts", $html);
    }

    public function test_redemptions_received_show_five_then_the_rest(): void
    {
        $this->signIn(Role::Merchant);
        $total = Merchant::where('name', 'Farragut Grind')->sole()->redemptions()->count();
        $this->assertGreaterThan(5, $total);
        $loaded = min($total, ShowMore::CAP);

        $html = $this->get('/merchant')->assertOk()->getContent();
        $this->assertSame(['rows' => $loaded, 'folded' => $loaded - ShowMore::RECEIVED], $this->list($html, 'received-rows'));
        $this->assertButton($html, 'received-rows', "Show all redemptions ({$loaded})");
    }

    public function test_wallet_history_and_recent_redemptions(): void
    {
        $this->signIn(Role::Employee);
        $employee = Employee::orderBy('id')->firstOrFail();
        $recent = min($employee->redemptions()->count(), ShowMore::CAP);
        $this->assertGreaterThan(ShowMore::RECENT_REDEMPTIONS, $recent);

        for ($i = $employee->ledgerEntries()->count(); $i < ShowMore::CAP + 5; $i++) {
            CreditLedgerEntry::create(['employee_id' => $employee->id, 'amount_cents' => 100, 'type' => CreditLedgerEntry::TYPE_ALLOWANCE, 'description' => 'Extra allowance', 'created_at' => Carbon::now()->subDays(100)]);
        }
        $total = $employee->ledgerEntries()->count();

        $html = $this->get('/employee')->assertOk()->getContent();
        $this->assertSame(['rows' => ShowMore::CAP, 'folded' => ShowMore::CAP - ShowMore::HISTORY], $this->list($html, 'history-rows'));
        $this->assertButton($html, 'history-rows', 'Show all transactions ('.ShowMore::CAP.')');
        $this->assertStringContainsString("Show all {$total} transactions", $html);
        $this->assertSame(['rows' => $recent, 'folded' => $recent - ShowMore::RECENT_REDEMPTIONS], $this->list($html, 'recent-rows'));
        $this->assertButton($html, 'recent-rows', "Show all redemptions ({$recent})");

        $html = $this->get('/employee?history=1')->assertOk()->getContent();
        $this->assertSame($total, $this->list($html, 'history-rows')['rows']);
        $this->assertStringNotContainsString("Show all {$total} transactions", $html);
    }

    public function test_employer_purchases_and_recently_given(): void
    {
        $this->signIn(Role::EmployerAdmin);
        $employer = Employer::firstOrFail();
        $employee = Employee::orderBy('id')->firstOrFail();
        $purchases = fn () => $employer->creditPurchases()->count();
        for ($i = $purchases(); $i < 12; $i++) {
            CreditPurchase::create(['employer_id' => $employer->id, 'amount_cents' => 1000, 'reference' => 'TEST-'.$i, 'purchased_at' => now()->subDays(200 + $i), 'status' => CreditPurchase::STATUS_SIMULATED]);
        }
        for ($i = 0; $i < 12; $i++) {
            CreditAllocation::create(['employer_id' => $employer->id, 'employee_id' => $employee->id, 'amount_cents' => 100 + $i, 'allocated_at' => now()->subDays(300 + $i), 'occasion' => Occasion::Recognition]);
        }

        $html = $this->get('/employer')->assertOk()->getContent();
        $rows = $this->list($html, 'credit-activity-rows');
        $this->assertGreaterThan(ShowMore::PURCHASES, $rows['rows']);
        $this->assertSame($rows['rows'] - ShowMore::PURCHASES, $rows['folded']);
        $this->assertButton($html, 'credit-activity-rows', "Show all purchases ({$rows['rows']})");

        $given = $this->list($html, 'allocation-rows');
        $this->assertGreaterThan(ShowMore::GIVEN, $given['rows']);
        $this->assertSame($given['rows'] - ShowMore::GIVEN, $given['folded']);
        $this->assertButton($html, 'allocation-rows', "Show all allocations ({$given['rows']})");
    }

    public function test_employer_purchases_over_the_cap_keep_a_reload_link(): void
    {
        $this->signIn(Role::EmployerAdmin);
        $employer = Employer::firstOrFail();
        for ($i = $employer->creditPurchases()->count(); $i < ShowMore::CAP + 4; $i++) {
            CreditPurchase::create(['employer_id' => $employer->id, 'amount_cents' => 1000, 'reference' => 'TEST-'.$i, 'purchased_at' => now()->subDays(200 + $i), 'status' => CreditPurchase::STATUS_SIMULATED]);
        }
        $total = $employer->creditPurchases()->count();

        $html = $this->get('/employer')->assertOk()->getContent();
        $this->assertSame(ShowMore::CAP, $this->list($html, 'credit-activity-rows')['rows']);
        $this->assertStringContainsString('all_purchases=1', $html);

        $html = $this->get('/employer?all_purchases=1')->assertOk()->getContent();
        $this->assertGreaterThanOrEqual($total, $this->list($html, 'credit-activity-rows')['rows']);
    }

    public function test_admin_home_recent_activity_shows_five(): void
    {
        $admin = $this->signIn(Role::PlatformAdmin);
        for ($i = 0; $i < 8; $i++) {
            Feedback::forceCreate(['user_id' => $admin->id, 'status' => 'new', 'page_url' => '/x', 'category' => 'functionality', 'rating' => 3, 'severity' => 'nice_to_have', 'comment' => 'Test feedback.']);
        }

        $html = $this->get('/admin')->assertOk()->getContent();
        $rows = $this->list($html, 'recent-activity-rows');
        $this->assertGreaterThan(ShowMore::ACTIVITY, $rows['rows']);
        $this->assertSame($rows['rows'] - ShowMore::ACTIVITY, $rows['folded']);
        $this->assertButton($html, 'recent-activity-rows', "Show all activity ({$rows['rows']})");
    }

    public function test_lists_that_need_a_decision_are_never_folded(): void
    {
        $this->signIn(Role::PlatformAdmin);

        foreach (['/bid-admin', '/bid-admin/corrections', '/bid-admin/applications'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('data-more', $html, $path);
            $this->assertStringNotContainsString('data-show-more', $html, $path);
        }
    }

    public function test_an_empty_list_has_no_button(): void
    {
        $this->newMerchant();

        $html = $this->get('/merchant')->assertOk()->assertSee('No payouts yet.')->getContent();
        $this->assertStringNotContainsString('payout-rows', $html);
    }
}
