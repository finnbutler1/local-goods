<?php

namespace Tests\Feature;

use App\Models\MerchantApplication as Application;
use App\Services\Ai\ClaudeClient;
use App\Services\Districts\MerchantApplications;
use App\Services\Eligibility\MerchantEligibilityMatcher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesDcAddresses;
use Tests\Concerns\MakesApplications;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Merchant Eligibility Matching only suggests (ADR 0031): whatever it says,
 * the application stops at eligibility_checked and a person decides, for
 * or against it. It sees the same four facts as for candidates, with no
 * business activity, and never the independence answer or the email. The
 * prompt is unchanged.
 */
class ApplicationEligibilitySuggestionTest extends TestCase
{
    use FakesDcAddresses;
    use MakesApplications;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeDcAddresses();
    }

    public function test_an_independent_suggestion_does_not_approve_and_a_person_can_decline_against_it(): void
    {
        $this->eligibilityReply('independent', 0.95);

        $checked = $this->checked();

        $this->assertSame(Application::ELIGIBILITY_CHECKED, $checked->status);
        $this->assertTrue($checked->ai_eligible);
        $this->assertSame('independent', $checked->ai_classification);
        $declined = app(MerchantApplications::class)->decline($checked, $this->staff(), 'We know this shop is one of a chain of eight.');
        $this->assertSame(Application::DECLINED, $declined->status);
    }

    public function test_a_chain_suggestion_does_not_decline_and_a_person_can_approve_against_it(): void
    {
        $this->eligibilityReply('national_chain', 0.8);

        $checked = $this->checked();

        $this->assertSame(Application::ELIGIBILITY_CHECKED, $checked->status);
        $this->assertFalse($checked->ai_eligible);
        $approved = app(MerchantApplications::class)->approve($checked, $this->staff(), 'Checked: the name is shared but the owner is local with one shop.');
        $this->assertSame(Application::BID_APPROVED, $approved->status);
    }

    public function test_an_ai_failure_is_undetermined_and_needs_a_person(): void
    {
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient(new \RuntimeException('API down')));

        $checked = $this->checked();

        $this->assertSame(Application::ELIGIBILITY_CHECKED, $checked->status);
        $this->assertSame('undetermined', $checked->ai_classification);
        $this->assertTrue($checked->ai_needs_review);
        $this->assertSame(MerchantEligibilityMatcher::FALLBACK_REASONING, $checked->ai_reasoning);
    }

    public function test_the_ai_sees_only_name_address_category_and_a_blank_activity_with_the_frozen_prompt(): void
    {
        $fake = $this->eligibilityReply();

        $checked = $this->checked();

        $this->assertCount(1, $fake->calls);
        $this->assertSame(['name' => 'Kettle & Crumb', 'address' => '1700 K St NW', 'category' => 'cafe', 'business_activity' => ''], json_decode($fake->calls[0]['user'], true));
        $this->assertStringNotContainsString('neighbors', $fake->calls[0]['user'], 'never the independence answer');
        $this->assertStringNotContainsString('example.com', $fake->calls[0]['user'], 'never the email');
        $this->assertSame((string) file_get_contents(MerchantEligibilityMatcher::promptPath()), $fake->calls[0]['system']);
        $this->assertSame(config('eligibility.frozen_prompt_version'), $checked->ai_prompt_version);
    }
}
