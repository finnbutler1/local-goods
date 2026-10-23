<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The toast (<x-flash />): one per layout, at the top of the screen, saying
 * what the last action did. Status and notice are polite and fade out on
 * their own; errors are alerts and stay. Each can be dismissed.
 */
class ToastTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_status_toast_is_polite_dismissable_and_fades_out(): void
    {
        $view = $this->blade('<x-flash status="$50.00 sent to J.R. They can spend it now." :notice="null" :error="null" />');

        $view->assertSee('role="status" data-toast="status"', false)
            ->assertSee('data-toast-autohide', false)
            ->assertSee('data-flash', false)
            ->assertSee('$50.00 sent to J.R. They can spend it now.')
            ->assertSee('<button type="button" data-toast-dismiss aria-label="Dismiss message"', false)
            ->assertSee('pointer-events-none fixed inset-x-0 top-3 z-50', false);
    }

    public function test_a_notice_is_polite_and_fades_out(): void
    {
        $this->blade('<x-flash :status="null" notice="Held for a quick review." :error="null" />')
            ->assertSee('role="status" data-toast="notice"', false)
            ->assertSee('data-toast-autohide', false)
            ->assertSee('border-accent bg-band', false);
    }

    public function test_an_error_is_an_alert_in_the_warning_colour_and_stays(): void
    {
        $this->blade('<x-flash :status="null" :notice="null" error="Nothing changed: this redemption is approved." />')
            ->assertSee('role="alert" data-toast="error"', false)
            ->assertDontSee('data-toast-autohide', false)
            ->assertSee('border-hold bg-hold-bg text-hold', false)
            ->assertSee('aria-label="Dismiss message"', false);
    }

    public function test_the_error_comes_first_so_it_gets_focus(): void
    {
        $html = (string) $this->blade('<x-flash status="Saved." :notice="null" error="Nothing changed." />');

        $this->assertLessThan(strpos($html, 'data-toast="status"'), strpos($html, 'data-toast="error"'));
    }

    public function test_samples_render_in_place_without_taking_focus(): void
    {
        $this->blade('<x-flash status="Saved." :notice="null" :error="null" :floating="false" :focus="false" />')
            ->assertDontSee('fixed inset-x-0', false)
            ->assertDontSee('data-flash', false)
            ->assertSee('Saved.');
    }

    public function test_nothing_is_rendered_without_a_message(): void
    {
        $this->assertSame('', trim((string) $this->blade('<x-flash />')));
    }

    public function test_fortifys_password_key_becomes_a_sentence(): void
    {
        $this->blade('<x-flash status="password-updated" :notice="null" :error="null" />')
            ->assertSee('Your password was changed.')
            ->assertDontSee('password-updated');
    }

    public function test_every_signed_in_page_shows_the_session_message_once_at_the_top(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::EmployerAdmin);

        $html = $this->withSession(['status' => 'A test confirmation.'])->get('/employer')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'A test confirmation.'));
        $this->assertLessThan(strpos($html, '<main'), strpos($html, 'data-toast="status"'));
    }
}
