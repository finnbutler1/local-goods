<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\SweepsPages;
use Tests\TestCase;

/**
 * Phone-first rules that a page's HTML can show: long values never split
 * mid-word, nothing hides behind the tab bar or the iPhone home indicator,
 * and controls are big enough to tap.
 */
class PhoneLayoutTest extends TestCase
{
    use RefreshDatabase;
    use SweepsPages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_no_page_splits_words_with_break_all(): void
    {
        // break-all cuts emails and ids at any letter ("butle / rf@c").
        foreach (File::allFiles(resource_path('views')) as $file) {
            $this->assertStringNotContainsString('break-all', $file->getContents(), "{$file->getRelativePathname()} uses break-all.");
        }

        $this->sweepPages(fn (string $html, string $where) => $this->assertStringNotContainsString('break-all', $html, "break-all on {$where}"));
    }

    public function test_emails_break_only_between_their_parts(): void
    {
        $admin = $this->signIn(Role::PlatformAdmin);
        $html = $this->actingAs($admin)->get('/admin')->assertOk()->getContent();

        // <x-breakable>: the wrapping class, and <wbr> only after @ . / _ -.
        $this->assertMatchesRegularExpression('~<span class="wrap-anywhere-safe">(?:[^<]|<wbr>)+@<wbr>(?:[^<]|<wbr>)+\.<wbr>[a-z]+</span>~', $html);
        $this->assertStringContainsString('<x-breakable :value="$reviewer->adminEmail()" />', File::get(resource_path('views/admin/reviewers.blade.php')));
        $this->assertStringContainsString('<x-breakable :value="auth()->user()->email" />', File::get(resource_path('views/account/show.blade.php')));
    }

    public function test_the_wrapping_class_never_splits_a_word_that_fits(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.wrap-anywhere-safe \{\s*overflow-wrap: break-word;\s*word-break: normal;/', $css);
    }

    public function test_the_signed_in_layout_keeps_clear_of_the_tab_bar_and_the_home_indicator(): void
    {
        $html = $this->actingAs($this->signIn(Role::Employee))->get('/employee')->assertOk()->getContent();

        // env() is only non-zero with viewport-fit=cover.
        $this->assertStringContainsString('viewport-fit=cover', $html);
        // The last content clears the tab bar plus the home indicator.
        $this->assertMatchesRegularExpression('~<main id="main"[^>]*pb-\[calc\(10rem\+env\(safe-area-inset-bottom\)\)\]~', $html);
        // The tab bar itself grows by the inset, and keeps clear of a landscape notch.
        $this->assertMatchesRegularExpression('~<nav aria-label="App" class="fixed inset-x-0 bottom-0[^"]*pb-\[env\(safe-area-inset-bottom\)\][^"]*"~', $html);
        $this->assertMatchesRegularExpression('~<nav aria-label="App" class="fixed inset-x-0 bottom-0[^"]*pl-\[env\(safe-area-inset-left\)\]~', $html);
        // The Feedback button sits above the tab bar and the indicator.
        $this->assertStringContainsString('bottom-[calc(4.75rem+env(safe-area-inset-bottom))]', $html);
    }

    public function test_toasts_keep_clear_of_the_notch(): void
    {
        $this->blade('<x-flash status="Saved." :notice="null" :error="null" />')
            ->assertSee('top-[calc(0.75rem+env(safe-area-inset-top))]', false);
    }

    public function test_buttons_and_standalone_links_are_44px_to_tap_on_phones(): void
    {
        $this->blade('<x-button size="sm">A</x-button>')->assertSee('max-sm:min-h-11 max-sm:min-w-11', false);
        $this->blade('<x-button>B</x-button>')->assertSee('min-h-11', false);
        $this->blade('<x-button size="lg">C</x-button>')->assertSee('min-h-11', false);

        $css = File::get(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/@utility tap-target \{\s*@media \(width < 40rem\) \{[^}]*min-height: 2\.75rem;/', $css);
        $this->assertStringContainsString("label:has(> input:is([type='checkbox'], [type='radio']))", $css);

        // The judge tour and the wallet's merchant links use it.
        $judge = $this->actingAs($this->signIn(Role::Judge))->get('/judge')->assertOk()->getContent();
        $this->assertStringContainsString('class="tap-target text-primary underline', $judge);
        $wallet = $this->actingAs($this->signIn(Role::Employee))->get('/employee')->assertOk()->getContent();
        $this->assertStringContainsString('class="tap-target mt-2 self-start', $wallet);
    }
}
