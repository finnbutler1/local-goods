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
 * mid-word.
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
}
