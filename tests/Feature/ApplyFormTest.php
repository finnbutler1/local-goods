<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Districts\ApplyController;
use App\Models\MerchantApplication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesApplications;
use Tests\TestCase;

/**
 * The public "Apply to join" form (ADR 0031): the prototype notice before
 * any field, a required acknowledgement, a privacy note, a honeypot and a
 * per-IP limit. Sending it calls nothing outside (no AI, no address lookup,
 * no email).
 */
class ApplyFormTest extends TestCase
{
    use MakesApplications;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
    }

    /** @return array<string, string> */
    private function form(array $overrides = []): array
    {
        return [...$this->applicationData(), 'prototype' => '1', ...$overrides];
    }

    public function test_the_prototype_notice_comes_before_any_field(): void
    {
        $html = $this->get('/apply')->assertOk()->getContent();

        $notice = strpos($html, ApplyController::PROTOTYPE_NOTICE);
        $this->assertNotFalse($notice);
        $main = strpos($html, 'id="main"');
        $firstField = min(array_filter([strpos($html, '<input', $main), strpos($html, '<select', $main), strpos($html, '<textarea', $main)]));
        $this->assertLessThan($firstField, $notice, 'the notice is before the first field');
        $this->assertStringContainsString(ApplyController::ACKNOWLEDGEMENT, $html);
    }

    public function test_the_privacy_note_says_what_is_stored_where_it_goes_and_when_it_is_deleted(): void
    {
        $this->get('/apply')->assertOk()
            ->assertSee('business name, address, category, contact email and your answer about independence')
            ->assertSee('The business name, address and category go to an AI model')
            ->assertSee('Your email and your answer don\'t.', false)
            ->assertSee('Nothing is shared or sold.')
            ->assertSee('Applications are deleted on request, or after December 31, 2026.');
    }

    public function test_applying_stores_the_application_and_shows_the_private_link_once(): void
    {
        $html = $this->post('/apply', $this->form())->assertOk()
            ->assertSee(ApplyController::PROTOTYPE_NOTICE)
            ->assertSee('Save this private link now')
            ->assertSee('Step 2 of 5: waiting for the eligibility check.')
            ->getContent();

        $application = MerchantApplication::where('is_sample', false)->sole();
        $this->assertSame(MerchantApplication::APPLIED, $application->status);
        $this->assertNotNull($application->prototype_acknowledged_at);
        $this->assertFalse($application->is_sample);
        $this->assertMatchesRegularExpression('#/apply/status\#'.$application->id.'\.[A-Za-z0-9]{40}"#', $html);
        Http::assertNothingSent();
    }

    public function test_the_acknowledgement_is_required(): void
    {
        $this->from('/apply')->post('/apply', $this->form(['prototype' => '']))
            ->assertRedirect('/apply')->assertSessionHasErrors(['prototype' => 'Tick "I understand this is a prototype" to apply.']);
        $this->assertSame(0, MerchantApplication::where('is_sample', false)->count());
    }

    public function test_fields_are_checked(): void
    {
        $this->from('/apply')->post('/apply', $this->form(['contact_email' => 'not an email', 'category' => 'bank', 'independence_answer' => 'Short', 'business_name' => '']))
            ->assertSessionHasErrors(['contact_email', 'category', 'independence_answer', 'business_name']);
        $this->assertSame(0, MerchantApplication::where('is_sample', false)->count());
    }

    public function test_the_honeypot_stores_nothing_but_looks_the_same(): void
    {
        $this->post('/apply', $this->form(['website' => 'http://spam.example']))->assertOk()->assertSee('Application sent');

        $this->assertSame(0, MerchantApplication::where('is_sample', false)->count());
    }

    public function test_an_ip_may_send_three_applications_in_ten_minutes(): void
    {
        foreach (range(1, 3) as $i) {
            $this->post('/apply', $this->form(['contact_email' => "owner{$i}@example.com"]))->assertOk();
        }
        $this->post('/apply', $this->form(['contact_email' => 'owner4@example.com']))->assertStatus(429);

        $this->assertSame(3, MerchantApplication::where('is_sample', false)->count());
        $this->travel(11)->minutes();
        $this->post('/apply', $this->form(['contact_email' => 'owner5@example.com']))->assertOk();
    }

    public function test_judges_see_the_form_but_cannot_send_it(): void
    {
        $this->signIn(Role::Judge);

        $this->get('/apply')->assertOk()->assertSee('Read-only.');
        $this->post('/apply', $this->form())->assertForbidden();
        $this->assertSame(0, MerchantApplication::where('is_sample', false)->count());
    }
}
