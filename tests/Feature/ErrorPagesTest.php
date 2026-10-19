<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccessibilityAudit;
use Tests\TestCase;

/**
 * Errors get a page in plain English that says what to do next
 * (resources/views/errors), not the framework's bare default.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/_test/abort/{code}', fn (int $code) => abort($code));
    }

    public static function codes(): array
    {
        return [
            '403' => [403, "You can't open this page"],
            '404' => [404, "We can't find that page"],
            '419' => [419, 'This page expired'],
            '429' => [429, 'Too many tries'],
            '500' => [500, 'Something went wrong on our side'],
            '503' => [503, 'Local Goods is being updated'],
        ];
    }

    #[DataProvider('codes')]
    public function test_each_error_has_a_friendly_accessible_page(int $code, string $heading): void
    {
        $html = $this->get("/_test/abort/{$code}")->assertStatus($code)->assertSee($heading)->assertSee('Go to the home page')->getContent();

        $this->assertSame([], AccessibilityAudit::problems($html));
    }

    public function test_an_unknown_address_gets_the_friendly_404(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee("We can't find that page");
    }

    public function test_signed_in_people_are_sent_to_their_dashboard(): void
    {
        $this->actingAs($this->signIn(Role::EmployerAdmin))
            ->get('/_test/abort/404')->assertNotFound()->assertSee('Go to my dashboard');
    }

    public function test_our_own_reason_is_shown_but_not_the_framework_default(): void
    {
        // A judge trying to write: BlockJudgeWrites says why.
        $this->actingAs($this->signIn(Role::Judge))
            ->post(route('employer.purchases.store'), ['purchase_amount' => '10'])
            ->assertForbidden()->assertSee('Your account is read-only.');

        $this->get('/_test/abort/403')->assertDontSee('This action is unauthorized.');
    }

    public function test_the_500_page_warns_against_paying_twice(): void
    {
        $this->get('/_test/abort/500')->assertSee('check the page before trying again');
    }
}
