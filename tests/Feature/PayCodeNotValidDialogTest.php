<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Services\Ai\ClaudeClient;
use App\Services\PayCodes;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The cashier's "That code didn't work" dialog. Its text must be identical
 * for every reason a code is refused, and must never reveal which applies.
 */
class PayCodeNotValidDialogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
        $this->signIn(Role::Merchant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function codes(): PayCodes
    {
        return app(PayCodes::class);
    }

    private function grind(): Merchant
    {
        return Merchant::where('name', 'Farragut Grind')->firstOrFail();
    }

    private function jordan(): Employee
    {
        return Employee::orderBy('id')->firstOrFail();
    }

    /** The page the cashier sees after typing $code. */
    private function pageAfter(string $code): string
    {
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $code])->assertRedirect();

        return $this->get(route('merchant.dashboard'))->assertOk()->getContent();
    }

    private function dialogOf(string $html): ?string
    {
        return preg_match('#<dialog id="pay-code-dialog".*?</dialog>#s', $html, $m) ? $m[0] : null;
    }

    /** @return array<string, string> each way a code is refused, as the page the cashier gets */
    private function everyReason(): array
    {
        $pages = [];

        $pages['made up'] = $this->pageAfter('ZZZZZZ');
        Carbon::setTestNow(now()->addSeconds(61));

        $pages['malformed'] = $this->pageAfter('!!');
        Carbon::setTestNow(now()->addSeconds(61));

        $pages['expired'] = $this->pageAfter($this->expiredCode());
        Carbon::setTestNow(now()->addSeconds(61));

        $used = $this->codes()->issue($this->jordan(), $this->grind(), 850);
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $used->code]);
        $this->post(route('merchant.pay-code.confirm', $used));
        $pages['already used'] = $this->pageAfter($used->code);
        Carbon::setTestNow(now()->addSeconds(61));

        $other = Merchant::where('id', '!=', $this->grind()->id)->firstOrFail();
        $forOther = $this->codes()->issue($this->jordan(), $other, 850);
        $pages['another shop'] = $this->pageAfter($forOther->code);
        Carbon::setTestNow(now()->addSeconds(61));

        $cancelled = $this->codes()->issue($this->jordan(), $this->grind(), 850);
        $this->codes()->cancelByEmployee($cancelled);
        $pages['cancelled'] = $this->pageAfter($cancelled->code);

        return $pages;
    }

    private function expiredCode(): string
    {
        $code = $this->codes()->issue($this->jordan(), $this->grind(), 850)->code;
        Carbon::setTestNow(now()->addMinutes(11));

        return $code;
    }

    public function test_the_dialog_is_identical_for_every_way_a_code_is_refused(): void
    {
        $dialogs = array_map(fn ($html) => $this->dialogOf($html), $this->everyReason());

        $this->assertCount(6, $dialogs);
        foreach ($dialogs as $reason => $dialog) {
            $this->assertNotNull($dialog, "No dialog for: {$reason}");
        }
        $this->assertCount(1, array_unique($dialogs), 'The dialog differs between reasons.');
    }

    public function test_the_whole_page_message_is_the_same_too(): void
    {
        foreach ($this->everyReason() as $reason => $html) {
            $this->assertStringContainsString('id="pay_code-error"', $html, $reason);
            $this->assertStringContainsString(PayCodes::NOT_VALID, $html, $reason);
        }
    }

    public function test_the_dialog_says_what_is_possible_and_what_to_do_without_naming_one(): void
    {
        $dialog = $this->dialogOf($this->pageAfter('ZZZZZZ'));

        foreach (['That code didn\'t work', 'A character was typed wrong', 'Codes last 10 minutes', 'already used', 'different shop', 'cancelled', 'check the code on their screen', 'get a new one in their wallet', 'Try again'] as $text) {
            $this->assertStringContainsString($text, $dialog, $text);
        }
        $this->assertStringContainsString('aria-labelledby="pay-code-dialog-heading"', $dialog);
        $this->assertStringContainsString('data-return-to="pay_code"', $dialog);
        $this->assertStringNotContainsString('ZZZZZZ', $dialog, 'The typed code is never repeated.');
    }

    public function test_without_javascript_the_inline_message_and_field_state_remain(): void
    {
        $html = $this->pageAfter('ZZZZZZ');

        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertMatchesRegularExpression('/aria-describedby="pay_code-hint\s+pay_code-error\s*"/', $html);
        $this->assertStringContainsString('<p id="pay_code-error"', $html);
        $this->assertStringNotContainsString('<dialog id="pay-code-dialog" open', $html, 'Only script opens it.');
    }

    public function test_the_rate_limit_message_is_separate_and_opens_no_dialog(): void
    {
        for ($i = 0; $i < PayCodes::MAX_FAILURES; $i++) {
            $this->pageAfter('ZZZZZ'.($i + 2));
        }
        $html = $this->pageAfter('ZZZZZZ');

        $this->assertStringContainsString(PayCodes::TOO_MANY_TRIES, $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertNull($this->dialogOf($html));
    }

    public function test_no_dialog_on_a_fresh_page(): void
    {
        $this->assertNull($this->dialogOf($this->get(route('merchant.dashboard'))->getContent()));
    }

    public function test_the_script_returns_focus_to_the_field_and_selects_it(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('dialog[data-return-to]', $js);
        $this->assertStringContainsString("addEventListener('close'", $js);
        $this->assertStringContainsString('field?.focus()', $js);
        $this->assertStringContainsString('field?.select?.()', $js);
    }

    public function test_the_dialog_view_takes_no_variables(): void
    {
        $source = file_get_contents(resource_path('views/dashboards/partials/pay-code-not-valid-dialog.blade.php'));

        $this->assertDoesNotMatchRegularExpression('/\{\{|@if|@foreach|\$/', preg_replace('/\{\{--.*?--\}\}/s', '', $source));
    }
}
