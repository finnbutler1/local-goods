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
        // The footer (the last content, shown on phones too) clears the tab bar plus the home indicator.
        $this->assertMatchesRegularExpression('~<footer class="[^"]*pb-\[calc\(10rem\+env\(safe-area-inset-bottom\)\)\][^"]*lg:pb-8">\s*Demonstration project~', $html);
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

    /** @return list<string> */
    private static function fieldProblems(string $html): array
    {
        $problems = [];
        preg_match_all('~<(input|select|textarea)\b[^>]*>~', $html, $tags);
        foreach ($tags[0] as $tag) {
            $attr = fn (string $name) => preg_match('~\s'.$name.'="([^"]*)"~', $tag, $m) ? $m[1] : null;
            $name = (string) $attr('name');
            $type = $attr('type');

            if (preg_match('~\btext-(xs|sm)\b~', (string) $attr('class'))) {
                $problems[] = "{$name}: smaller than 16px (iOS zooms in)";
            }
            if (preg_match('~(^|_)(amount|price|budget|allowance)\]?$~', $name) && $attr('inputmode') !== 'decimal') {
                $problems[] = "{$name}: a money field without inputmode=\"decimal\"";
            }
            if ($name === 'email' && ! in_array($type, ['email', 'hidden'], true)) {
                $problems[] = 'email: not type="email"';
            }
            if (str_starts_with($name, 'items[') && ($type !== 'number' || $attr('inputmode') !== 'numeric')) {
                $problems[] = "{$name}: a quantity that isn't a numeric field";
            }
            if ($name === 'pay_code' && ($attr('inputmode') !== 'text' || $attr('autocapitalize') !== 'characters' || $attr('autocomplete') !== 'off')) {
                $problems[] = 'pay_code: changed from text, capitals, no autocomplete';
            }
        }

        return array_values(array_unique($problems));
    }

    public function test_every_field_is_16px_and_brings_up_the_right_keyboard(): void
    {
        $found = [];
        $check = function (string $html, string $where) use (&$found) {
            if ($problems = self::fieldProblems($html)) {
                $found[$where] = $problems;
            }
        };
        $this->sweepPages($check);

        // Fields that only show up in a state: a menu to pay from, and a merchant's menu editor.
        $grind = \App\Models\Merchant::where('name', 'Farragut Grind')->value('id');
        $employee = $this->signIn(Role::Employee);
        $check($this->actingAs($employee)->get('/employee?merchant='.$grind)->assertOk()->getContent(), 'redeem from a menu');
        $check($this->actingAs($employee)->get('/employee?tab=in-person&pay_merchant='.$grind)->assertOk()->getContent(), 'pay in person from a menu');

        $this->assertSame([], $found, "Fields:\n".collect($found)->map(fn ($p, $w) => "{$w}: ".implode('; ', $p))->implode("\n"));

        $css = File::get(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/:is\(input, select, textarea\) \{\s*font-size: max\(1rem, 1em\);/', $css);
    }

    public function test_the_known_fields_keep_their_keyboards(): void
    {
        $html = $this->actingAs($this->signIn(Role::EmployerAdmin))->get('/employer')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<input id="purchase_amount" name="purchase_amount" type="text" inputmode="decimal"~', $html);

        $login = File::get(resource_path('views/auth/login.blade.php'));
        $this->assertStringContainsString('name="email" type="email" autocomplete="username"', $login);

        $payCode = File::get(resource_path('views/dashboards/partials/pay-code-field.blade.php'));
        $this->assertStringContainsString('inputmode="text" enterkeyhint="go"', $payCode);
    }

    public function test_nothing_only_appears_on_hover(): void
    {
        // A phone can't hover: whatever hover shows, a tap (data-open, set by app.js) shows too.
        $this->sweepPages(function (string $html, string $where) {
            preg_match_all('~class="([^"]*\bhover:(?:block|flex|visible|opacity-100)[^"]*)"~', $html, $m);
            foreach ($m[1] as $class) {
                $this->assertMatchesRegularExpression('~\b(group-)?data-open:(block|flex|visible|opacity-100)\b~', $class, "Hover-only on {$where}: {$class}");
            }
        });

        $impact = $this->actingAs($this->signIn(Role::BidAdmin))->get('/bid-admin/impact')->assertOk()->getContent();
        $this->assertStringContainsString('data-bar>', $impact);
        $this->assertStringContainsString("closest('[data-bar]')", File::get(resource_path('js/app.js')));
    }

    public function test_page_titles_scale_smoothly_with_the_screen(): void
    {
        $css = File::get(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/@utility text-title \{\s*font-size: clamp\(2rem, 1\.3rem \+ 3\.5vw, 3rem\);/', $css);

        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('~<h1\b[^>]*class="([^"]*)"~', $file->getContents(), $m);
            foreach ($m[1] as $class) {
                $this->assertFalse(str_contains($class, 'text-4xl') && str_contains($class, 'sm:text-5xl'), "{$file->getRelativePathname()}: an h1 jumps from text-4xl to sm:text-5xl; use text-title.");
            }
        }
    }
}
