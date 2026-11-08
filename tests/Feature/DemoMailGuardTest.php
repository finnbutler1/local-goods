<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\Merchant;
use App\Models\User;
use App\Services\ReviewerLinks;
use App\Support\DemoMailGuard;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * No email ever goes to a demo account's fictional domain, in any
 * environment (DemoMailGuard). Only the exact domains in
 * config demo.mail_blocked_domains: real reviewers' domains, lookalikes and
 * uselocalgoods.com itself always get their mail, and reviewer magic links
 * (never emailed) keep working.
 */
class DemoMailGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The array mailer really runs the send path (events included) and keeps what it sent.
        config(['mail.default' => 'array']);
    }

    public static function blocked(): array
    {
        return [
            ['jordan.rivera@meridianpolicygroup.com'], ['alex.morgan@meridianpolicygroup.com'],
            ['priya.natarajan@farragutgrind.com'], ['sam.taylor@goldentriangle.uselocalgoods.com'],
            ['Jordan.Rivera@MeridianPolicyGroup.COM'], ['  priya.natarajan@farragutgrind.com  '],
        ];
    }

    public static function notBlocked(): array
    {
        return [
            ['someone@cua.edu'], ['someone@gmail.com'], ['finn@uselocalgoods.com'], ['public.judge@example.com'],
            ['x@farragutgrind.co'], ['x@notfarragutgrind.com'], ['x@farragutgrind.com.evil.com'],
            ['x@mail.farragutgrind.com'], ['x@uselocalgoods.com'], ['x@other.uselocalgoods.com'],
            ['farragutgrind.com'], [''], [null],
        ];
    }

    #[DataProvider('blocked')]
    public function test_each_demo_domain_is_blocked(string $address): void
    {
        $this->assertTrue(DemoMailGuard::isBlocked($address));
    }

    #[DataProvider('notBlocked')]
    public function test_only_the_exact_demo_domains_are_blocked(?string $address): void
    {
        $this->assertFalse(DemoMailGuard::isBlocked($address));
    }

    public function test_the_list_is_exactly_the_demo_domains(): void
    {
        $this->assertSame(['meridianpolicygroup.com', 'farragutgrind.com', 'goldentriangle.uselocalgoods.com'], DemoMailGuard::domains());
    }

    public static function environments(): array
    {
        return [['local'], ['testing'], ['production']];
    }

    #[DataProvider('environments')]
    public function test_mail_to_a_demo_account_is_never_sent_in_any_environment(string $env): void
    {
        $this->app['env'] = $env;

        foreach (['to', 'cc', 'bcc'] as $field) {
            Mail::raw('Hello', fn (Message $m) => $m->to('someone@cua.edu')->subject('Test')->{$field}('jordan.rivera@meridianpolicygroup.com'));
        }
        $this->assertCount(0, $this->sent(), 'a demo address anywhere among the recipients stops the whole message');

        Mail::raw('Hello', fn (Message $m) => $m->to('someone@cua.edu')->subject('Test'));
        Mail::raw('Hello', fn (Message $m) => $m->to('someone@gmail.com')->cc('finn@uselocalgoods.com')->subject('Test'));
        $this->assertCount(2, $this->sent());
    }

    public function test_a_mail_notification_to_a_demo_account_is_never_sent(): void
    {
        $demo = User::factory()->create(['email' => 'priya.natarajan@farragutgrind.com']);
        $real = User::factory()->create(['email' => 'someone@gmail.com']);

        $demo->notify(new MailedNotice);
        $this->assertCount(0, $this->sent());

        $real->notify(new MailedNotice);
        $this->assertCount(1, $this->sent());
    }

    public function test_a_password_reset_email_is_never_sent_to_a_demo_account(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $demo = User::factory()->create(['email' => 'alex.morgan@meridianpolicygroup.com']);
        $real = User::factory()->create(['email' => 'someone@cua.edu']);

        // The page answers the same way for both, so it doesn't say which addresses are demo accounts.
        $this->post('/forgot-password', ['email' => $demo->email])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => $real->email])->assertSessionHas('status');

        Notification::assertNotSentTo($demo, ResetPassword::class);
        Notification::assertSentTo($real, ResetPassword::class);
    }

    public function test_demo_accounts_sign_in_with_their_password_without_any_email(): void
    {
        $user = User::factory()->role(Role::Employee)->create(['email' => 'jordan.rivera@meridianpolicygroup.com', 'password' => 'correct-horse-battery']);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery']);

        $this->assertAuthenticatedAs($user);
        $this->assertCount(0, $this->sent());
    }

    public static function reviewerEmails(): array
    {
        return [['judge@cua.edu'], ['someone@gmail.com'], ['reviewer@goldentriangle.uselocalgoods.com']];
    }

    #[DataProvider('reviewerEmails')]
    public function test_a_judge_link_can_still_be_created_and_used(string $email): void
    {
        config(['reviewers.judging_ends_at' => '2026-11-30 23:59']);
        $this->travelTo('2026-11-08 12:00');
        $this->signIn(Role::PlatformAdmin);

        // The admin's own Invite form, as on /admin/reviewers: the link is shown, never emailed.
        $this->post('/admin/reviewers', ['name' => 'Casey Morgan', 'email' => $email, 'reviewer_type' => 'contest_judge'])
            ->assertRedirect('/admin/reviewers')->assertSessionHas('new_reviewer_link');
        $link = session('new_reviewer_link')['url'];
        auth()->logout();
        $this->flushSession();

        $this->get('/judge-access')->assertOk();
        $this->post('/judge-access', ['link' => Str::after($link, '#')])->assertRedirect('/judge');

        $this->assertAuthenticatedAs(User::where('email', $email)->firstOrFail());
        $this->assertCount(0, $this->sent());
    }

    public function test_the_guard_never_changes_an_existing_judge_link(): void
    {
        config(['reviewers.judging_ends_at' => '2026-11-30 23:59']);
        $this->travelTo('2026-11-08 12:00');
        [$judge, $link] = app(ReviewerLinks::class)->invite('Casey Morgan', 'casey@cua.edu', ReviewerType::ContestJudge);
        $hash = $judge->invite_token_hash;

        Mail::raw('Hello', fn (Message $m) => $m->to('jordan.rivera@meridianpolicygroup.com')->subject('Test'));

        $this->assertSame($hash, $judge->fresh()->invite_token_hash);
        $this->assertSame($judge->id, app(ReviewerLinks::class)->userFor(Str::after($link, '#'))?->id);
    }

    public function test_a_demo_address_is_never_handed_to_stripe(): void
    {
        $merchant = new Merchant;
        $merchant->id = 7;

        $merchant->setRelation('user', new User(['email' => 'priya.natarajan@farragutgrind.com']));
        $this->assertSame('merchant-7@example.com', $merchant->stripeContactEmail());

        $merchant->setRelation('user', new User(['email' => 'owner@gmail.com']));
        $this->assertSame('owner@gmail.com', $merchant->stripeContactEmail());

        $merchant->setRelation('user', null);
        $this->assertSame('merchant-7@example.com', $merchant->stripeContactEmail());
    }

    public function test_the_check_command_says_the_guard_is_on_and_sends_nothing(): void
    {
        $this->artisan('demo:check-mail-guard')
            ->expectsOutput('Guarded domains: meridianpolicygroup.com, farragutgrind.com, goldentriangle.uselocalgoods.com')
            ->expectsOutput('jordan.rivera@meridianpolicygroup.com: blocked')
            ->expectsOutput('someone@cua.edu: not blocked')
            ->expectsOutput('The mail guard is on.')
            ->assertExitCode(0);

        config(['demo.mail_blocked_domains' => []]);
        $this->artisan('demo:check-mail-guard')
            ->expectsOutput('jordan.rivera@meridianpolicygroup.com: not blocked (WRONG)')
            ->assertExitCode(1);

        $this->assertCount(0, $this->sent());
    }

    private function sent(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }
}

class MailedNotice extends BaseNotification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Test')->line('Hello');
    }
}
