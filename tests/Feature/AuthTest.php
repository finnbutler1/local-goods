<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_uses_the_design_system(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<label for="email"', false)
            ->assertSee('<label for="password"', false)
            ->assertSee('There is no public sign-up.');
    }

    public function test_a_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_a_wrong_password_does_not_log_in(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_self_registration_is_off(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Someone New',
            'email' => 'new@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
        $this->get('/login')->assertDontSee('/register');
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(302);
        }

        // Even the right password is refused until the minute is up.
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_forgot_password_link_shows_only_when_mail_is_set_up(): void
    {
        config(['mail.default' => 'smtp']);
        $this->get('/login')
            ->assertSee('Forgot password?')
            ->assertDontSee('Contact the admin to reset your password.');

        foreach (['log', null] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->get('/login')
                ->assertDontSee('Forgot password?')
                ->assertDontSee(route('password.request'))
                ->assertSee('Contact the admin to reset your password.');
            $this->get('/forgot-password')
                ->assertDontSee('Email me a reset link')
                ->assertSee('Contact the admin to reset your password.');
        }
    }

    public function test_a_reset_link_can_be_requested_when_mail_is_set_up(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }
}
