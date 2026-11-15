<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxMerchant;
use App\Services\Sandbox\SandboxRoutes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\UsesFakeStripe;
use Tests\Concerns\UsesSandboxes;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The Merchant role follows where the money went (ADR 0045 addendum): the
 * role bar's Merchant button opens where the sandbox employee last paid,
 * the list beside it opens any merchant, and the wallet's confirmation has
 * "See this as the merchant". Only inside a sandbox, only the sandbox's own
 * merchant login, and never a write to the live database.
 */
class SandboxMerchantSwitchTest extends TestCase
{
    use RefreshDatabase, UsesFakeStripe, UsesSandboxes;

    /** @var list<string> */
    private array $mainWrites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandboxes();
        $this->fakeStripe();
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient(fn () => json_encode(
            ['risk_score' => 10, 'decision' => 'clear', 'explanation' => 'Looks like a normal purchase.'])));
    }

    /** The same watch as SandboxWalkthroughTest: any write to the live connection is recorded. */
    private function watchMainConnection(): void
    {
        $main = DB::getDefaultConnection();
        DB::listen(function (QueryExecuted $query) use ($main) {
            if ($query->connectionName === Sandbox::CONNECTION) {
                return;
            }
            if (! preg_match('/^\s*select\b/i', $query->sql)) {
                $this->mainWrites[] = "[{$query->connectionName}] {$query->sql}";
            }
            $this->assertSame($main, $query->connectionName, 'Only the live connection and the sandbox are ever used');
        });
    }

    /** Starts a sandbox and gives the employee credits to spend. */
    private function startWithCredits(): void
    {
        $this->startSandbox();
        $employeeId = $this->insideSandbox(fn () => Employee::whereHas('user', fn ($q) => $q->where('email', SandboxRoutes::email('employee')))->value('id'));
        $this->sandboxPost(route('employer.allocations.store'), ['mode' => 'individual', 'occasion' => 'recognition', 'amount' => '100', 'employee_id' => $employeeId])
            ->assertSessionHasNoErrors();
    }

    /** A merchant the employee can pay at, other than Farragut Grind. */
    private function otherMerchant(): Merchant
    {
        return $this->insideSandbox(fn () => app(SandboxMerchant::class)->pickable()
            ->first(fn (Merchant $m) => $m->name !== 'Farragut Grind'));
    }

    private function grindId(): int
    {
        return (int) $this->insideSandbox(fn () => Merchant::where('name', 'Farragut Grind')->value('id'));
    }

    /** @return list<int> merchants linked to the sandbox merchant login */
    private function linkedToSandboxMerchant(): array
    {
        return $this->insideSandbox(fn () => Merchant::whereHas('user', fn ($q) => $q->where('email', SandboxRoutes::email('merchant')))
            ->orderBy('id')->pluck('id')->all());
    }

    public function test_before_any_payment_merchant_opens_farragut_grind_not_the_seeded_history(): void
    {
        $this->startSandbox();
        // Jordan's seeded history ends at another merchant; it doesn't count.
        $this->assertNotSame($this->grindId(), (int) $this->insideSandbox(fn () => Redemption::where('employee_id', Employee::orderBy('id')->value('id'))->orderByDesc('id')->value('merchant_id')));

        $this->switchRole('merchant')->assertRedirect(route('merchant.dashboard'))
            ->assertSessionHas('status', "You're now the Merchant (Farragut Grind).");
        $this->sandboxGet(route('merchant.dashboard'))->assertOk()->assertSee('Farragut Grind');
        $this->assertSame([$this->grindId()], $this->linkedToSandboxMerchant());
    }

    public function test_paying_at_another_merchant_makes_merchant_open_that_merchant_with_the_sale(): void
    {
        $this->seed();
        $liveLinks = Merchant::orderBy('id')->pluck('user_id', 'id')->all();
        $this->watchMainConnection();
        $this->startWithCredits();
        $other = $this->otherMerchant();

        $this->switchRole('employee');
        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $other->id, 'amount' => '13.37'])->assertSessionHasNoErrors();
        $sale = $this->insideSandbox(fn () => Redemption::orderByDesc('id')->first());
        $this->assertSame($other->id, $sale->merchant_id);
        $this->assertTrue($sale->isApproved());

        // The confirmation offers to see it as that merchant.
        $this->sandboxGet(route('employee.wallet'))->assertOk()
            ->assertSee('data-see-as-merchant', false)
            ->assertSee('name="merchant_id" value="'.$other->id.'"', false)
            ->assertSee('See this as the merchant');

        // The Merchant button follows the money.
        $this->switchRole('merchant')->assertSessionHas('status', "You're now the Merchant ({$other->name}).");
        $this->sandboxGet(route('merchant.dashboard'))->assertOk()->assertSee($other->name)->assertSee('$13.37');
        $this->assertSame([$other->id], $this->linkedToSandboxMerchant());

        // "See this as the merchant" does the same from the employee's side.
        $this->switchRole('employee');
        $this->sandboxPost(route('sandbox.role'), ['role' => 'merchant', 'merchant_id' => $other->id])->assertRedirect(route('merchant.dashboard'));
        $this->sandboxGet(route('merchant.dashboard'))->assertOk()->assertSee('$13.37');

        $this->assertSame([], $this->mainWrites, 'A sandbox request wrote to the live database');
        $this->assertSame($liveLinks, Merchant::orderBy('id')->pluck('user_id', 'id')->all(), 'Live merchant logins were changed');
    }

    public function test_the_picker_opens_any_merchant_and_each_sees_only_its_own_sales(): void
    {
        $this->watchMainConnection();
        $this->startWithCredits();
        $other = $this->otherMerchant();

        $this->switchRole('employee');
        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $this->grindId(), 'amount' => '11.11'])->assertSessionHasNoErrors();
        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $other->id, 'amount' => '13.37'])->assertSessionHasNoErrors();

        // The role bar is just the five role buttons: no merchant list there any more.
        $this->sandboxGet(route('employee.wallet'))->assertOk()
            ->assertDontSee('sandbox-merchant-pick', false)
            ->assertDontSee('data-sandbox-merchant-picker', false);

        $this->sandboxPost(route('sandbox.role'), ['role' => 'merchant', 'merchant_id' => $other->id])->assertRedirect(route('merchant.dashboard'));
        $this->sandboxGet(route('merchant.dashboard'))->assertOk()->assertSee($other->name)->assertSee('$13.37')->assertDontSee('$11.11');
        $this->assertSame([$other->id], $this->linkedToSandboxMerchant());

        // The merchant page header says who you are and offers a labelled list (a <details>, no JavaScript needed).
        $this->sandboxGet(route('merchant.dashboard'))
            ->assertSee('Viewing as '.$other->name)
            ->assertSee('Change merchant')
            ->assertSee('data-sandbox-merchant-picker', false)
            ->assertSee('Choose which merchant to view as')
            ->assertSee('name="merchant_id" value="'.$this->grindId().'"', false);

        $this->sandboxPost(route('sandbox.role'), ['role' => 'merchant', 'merchant_id' => $this->grindId()])->assertRedirect(route('merchant.dashboard'));
        $this->sandboxGet(route('merchant.dashboard'))->assertOk()->assertSee('$11.11')->assertDontSee('$13.37');
        $this->assertSame([$this->grindId()], $this->linkedToSandboxMerchant(), 'Switching back unlinks the other merchant');

        $this->assertSame([], $this->mainWrites, 'A sandbox request wrote to the live database');
    }

    public function test_a_forged_merchant_id_changes_nothing(): void
    {
        $this->startSandbox();
        $other = $this->otherMerchant();
        // Inside this sandbox, a merchant run by some other login (not a sandbox one).
        $this->insideSandbox(function () use ($other) {
            $id = DB::table('users')->insertGetId(['name' => 'Someone Else', 'email' => 'someone@example.com', 'password' => '!', 'role' => Role::Merchant->value, 'created_at' => now(), 'updated_at' => now()]);
            Merchant::whereKey($other->id)->update(['user_id' => $id]);
        });
        $before = $this->insideSandbox(fn () => Merchant::orderBy('id')->pluck('user_id', 'id')->all());

        $this->sandboxPost(route('sandbox.role'), ['role' => 'merchant', 'merchant_id' => 999999])->assertNotFound();
        $this->sandboxPost(route('sandbox.role'), ['role' => 'merchant', 'merchant_id' => $other->id])->assertNotFound();
        $this->sandboxPost(route('sandbox.role'), ['role' => 'merchant', 'merchant_id' => 'abc'])->assertSessionHasErrors('merchant_id');
        $this->sandboxPost(route('sandbox.role'), ['role' => 'employee', 'merchant_id' => $this->grindId()])->assertSessionHasErrors('merchant_id');

        $this->assertSame($before, $this->insideSandbox(fn () => Merchant::orderBy('id')->pluck('user_id', 'id')->all()));
        // Still the employer: no one was signed in.
        $this->sandboxGet(route('employer.dashboard'))->assertOk();
    }

    public function test_outside_a_sandbox_switching_merchant_is_a_404_and_cannot_be_done_in_code(): void
    {
        $this->post(route('sandbox.role'), ['role' => 'merchant', 'merchant_id' => 1])->assertNotFound();
        config(['sandbox.enabled' => false]);
        $this->post('/sandbox/role', ['role' => 'merchant', 'merchant_id' => 1])->assertNotFound();

        $this->assertFalse(Sandbox::active());
        $this->expectException(LogicException::class);
        app(SandboxMerchant::class)->linkTo(new Merchant, new User);
    }
}
