<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxFiles;
use App\Services\Sandbox\SandboxRoutes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\UsesSandboxes;
use Tests\Support\AccessibilityAudit;
use Tests\TestCase;

/**
 * The live site can never reach a sandbox, and a sandbox can never reach the
 * live site (ADR 0045): the role bar doesn't exist without a sandbox, a
 * forged or ended cookie opens nothing, the sandbox guard finds no live
 * user, and live pages and commands never open a sandbox file.
 */
class SandboxIsolationTest extends TestCase
{
    use RefreshDatabase, UsesSandboxes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandboxes();
    }

    public function test_with_sandboxes_off_there_is_no_button_and_every_sandbox_address_is_a_404(): void
    {
        config(['sandbox.enabled' => false]);

        $this->get('/')->assertOk()->assertDontSee('Try it yourself');
        $this->actingAs($this->signIn(Role::Judge))->get(route('judge.dashboard'))->assertOk()->assertDontSee('Try it yourself');
        auth()->logout();
        $this->get('/sandbox')->assertNotFound();
        $this->get('/sandbox/home')->assertNotFound();
        foreach (['/sandbox/start', '/sandbox/role', '/sandbox/exit'] as $uri) {
            $this->post($uri, ['role' => 'reviewer'])->assertNotFound();
        }
        $this->assertSame([], glob($this->sandboxDir.'/sb-*') ?: []);
    }

    public function test_with_sandboxes_on_the_buttons_show_and_the_explainer_opens(): void
    {
        $this->get('/')->assertOk()->assertSee('Try it yourself')->assertSee(route('sandbox.show'));
        $this->actingAs($this->signIn(Role::Judge))->get(route('judge.dashboard'))->assertOk()->assertSee('Try it yourself');
        auth()->logout();
        $this->get('/sandbox')->assertOk()->assertSee('Start my sandbox');
    }

    public function test_without_an_sqlite_driver_sandboxes_count_as_off(): void
    {
        $this->assertTrue(Sandbox::enabled());
        // PDO's drivers can't be removed in a test; the check is this one expression.
        $this->assertStringContainsString("in_array('sqlite', PDO::getAvailableDrivers(), true)", file_get_contents(app_path('Services/Sandbox/Sandbox.php')));
    }

    public function test_the_role_bar_does_not_exist_on_the_live_site(): void
    {
        $admin = $this->signIn(Role::PlatformAdmin);

        // Signed out, and signed in as a live account: no sandbox, so 404 and nobody signed in as anyone.
        $this->post(route('sandbox.role'), ['role' => 'reviewer'])->assertNotFound();
        $this->actingAs($admin)->post(route('sandbox.role'), ['role' => 'employee'])->assertNotFound();
        $this->assertTrue(auth()->user()->is($admin));
        $this->get(route('sandbox.home'))->assertNotFound();
    }

    public function test_a_forged_or_tampered_cookie_opens_nothing(): void
    {
        // A token that was never handed out.
        $this->withCookie(Sandbox::COOKIE, SandboxFiles::newToken())->get(route('employer.dashboard'))
            ->assertRedirect(route('sandbox.show', ['ended' => 1]))->assertCookieExpired(Sandbox::COOKIE);
        $this->defaultCookies = [];
        $this->withCookie(Sandbox::COOKIE, SandboxFiles::newToken())->post(route('sandbox.role'), ['role' => 'reviewer'])->assertNotFound();
        $this->defaultCookies = [];

        // A real sandbox's token, but not encrypted and signed by this server: it reads as nothing.
        $this->startSandbox();
        $token = $this->sandboxJar[Sandbox::COOKIE];
        $this->withUnencryptedCookie(Sandbox::COOKIE, $token)->post(route('sandbox.role'), ['role' => 'reviewer'])->assertNotFound();
    }

    public function test_the_sandbox_guard_finds_no_live_user(): void
    {
        $admin = $this->signIn(Role::PlatformAdmin);
        $live = User::forceCreate(['name' => 'Live look-alike', 'email' => 'reviewer@sandbox.invalid', 'password' => 'not-a-real-password', 'role' => Role::PlatformAdmin]);

        $provider = Auth::guard(Sandbox::GUARD)->getProvider();
        $this->assertNull($provider->retrieveById($admin->id));
        $this->assertNull($provider->retrieveById($live->id), 'Even a live row with a sandbox-looking email is refused');
        $this->assertNull($provider->retrieveByCredentials(['email' => 'reviewer@sandbox.invalid']));
    }

    public function test_an_ended_sandbox_sends_the_visitor_back_to_start_and_is_deleted_by_prune(): void
    {
        $this->startSandbox();
        $id = $this->sandboxId();

        $this->travel(24)->hours();
        $this->travel(1)->minutes();
        $this->sandboxGet(route('employer.dashboard'))->assertRedirect(route('sandbox.show', ['ended' => 1]));
        $this->assertArrayNotHasKey(Sandbox::COOKIE, $this->sandboxJar);
        $this->get(route('sandbox.show', ['ended' => 1]))->assertOk()->assertSee('Your sandbox has ended.');

        $this->artisan('sandbox:prune')->assertSuccessful();
        $this->assertFileDoesNotExist($this->sandboxDir."/sb-{$id}.sqlite");
    }

    public function test_inside_a_sandbox_only_the_money_loop_opens(): void
    {
        $this->startSandbox();
        $this->switchRole('reviewer');

        $refused = 0;
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{') || SandboxRoutes::allows($name)
                || in_array($name, ['home', 'dashboard', 'storage.local', 'storage.local.upload'], true) || ! in_array('web', $route->gatherMiddleware(), true)) {
                continue;
            }
            $this->sandboxGet('/'.ltrim($route->uri(), '/'))->assertNotFound()->assertSee("That page isn't part of the sandbox", false);
            $refused++;
        }
        $this->assertGreaterThan(20, $refused);

        // Including the pages that hold real reviewers, Stripe and the public judge link.
        foreach (['/admin', '/admin/reviewers', '/admin/reconciliation', '/admin/feedback', '/account', '/login', '/eval'] as $uri) {
            $this->sandboxGet($uri)->assertNotFound();
        }
        $this->sandboxGet('/')->assertRedirect(route('sandbox.home'));
    }

    public function test_live_pages_and_commands_never_open_a_sandbox_file(): void
    {
        $this->startSandbox();
        $file = $this->sandboxDir.'/sb-'.$this->sandboxId().'.sqlite';
        $before = hash_file('sha256', $file);

        $opened = [];
        DB::listen(function (QueryExecuted $q) use (&$opened) {
            if ($q->connectionName === Sandbox::CONNECTION) {
                $opened[] = $q->sql;
            }
        });

        $this->artisan('ledger:check-solvency', ['--no-record' => true]);
        $this->artisan('demo-activity:tick', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('sandbox:prune')->assertSuccessful();
        $admin = $this->signIn(Role::PlatformAdmin);
        foreach (['/admin', '/admin/reconciliation', '/where-the-money-is', '/bid-admin/impact', '/'] as $uri) {
            $this->actingAs($admin)->get($uri)->assertOk();
        }

        $this->assertSame([], $opened);
        $this->assertArrayNotHasKey(Sandbox::CONNECTION, DB::getConnections());
        $this->assertSame($before, hash_file('sha256', $file), 'Nothing outside the sandbox changed its file');
        $this->assertFalse(Sandbox::active());
    }

    public function test_a_live_judge_stays_read_only_outside_the_sandbox(): void
    {
        $judge = $this->signIn(Role::Judge);
        $this->startSandbox();
        $this->sandboxPost(route('employer.purchases.store'), ['purchase_amount' => '10'])->assertSessionHasNoErrors();

        // Back on the live site, with the judge's own login: still refused.
        $this->actingAs($judge)->post(route('employer.purchases.store'), ['purchase_amount' => '10'])->assertForbidden();
    }

    public function test_sandbox_pages_pass_the_accessibility_audit_as_every_role(): void
    {
        $this->assertSame([], AccessibilityAudit::problems($this->get(route('sandbox.show'))->getContent()), '/sandbox');
        $this->startSandbox();

        foreach (array_keys(SandboxRoutes::ROLES) as $role) {
            $landing = $this->switchRole($role)->headers->get('Location');
            foreach ([route('sandbox.home'), $landing] as $uri) {
                $html = $this->sandboxGet($uri)->assertOk()->getContent();
                $this->assertSame([], AccessibilityAudit::problems($html), "{$uri} as {$role}");
                // The role bar: a labelled list of buttons, the current one marked.
                $this->assertStringContainsString('aria-label="Sandbox roles"', $html);
                $this->assertSame(1, substr_count($html, 'aria-pressed="true"'), "{$uri} as {$role}");
            }
        }
        $this->assertSame([], AccessibilityAudit::problems($this->sandboxGet('/admin')->getContent()), 'not part of the sandbox');
    }
}
