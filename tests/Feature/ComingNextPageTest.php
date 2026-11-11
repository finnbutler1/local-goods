<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The "Coming next" page: roadmap concepts, all labelled, none claimed as built or partnered. */
class ComingNextPageTest extends TestCase
{
    use RefreshDatabase;

    private const NO_PARTNER_SENTENCE = 'We have not partnered with any delivery service.';

    /** The page itself, without the shared header, footer and scripts. */
    private function main(): string
    {
        $html = $this->get('/coming-next')->assertOk()->getContent();
        preg_match('~<main\b.*?</main>~s', $html, $m);

        return $m[0] ?? $this->fail('No <main> on the page.');
    }

    private function text(): string
    {
        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->main())));
    }

    public function test_page_is_public_and_every_card_is_labelled_concept_not_built(): void
    {
        $this->get('/coming-next')->assertOk()->assertSee('Coming next');
        $html = $this->main();

        $cards = preg_match_all('/<section id="[^"]+" aria-label="Concept, not built: [^"]+" class="stop/', $html);
        $this->assertSame(4, $cards, 'Four cards, each with the label in its accessible name.');

        // The label is the first thing inside each card, and each drawing's name starts with it too.
        $this->assertSame(4, preg_match_all('/<p><span[^>]*>Concept, not built<\/span>\s*<\/p>\s*<div[^>]*station-sign/', $html));
        $this->assertSame(4, substr_count($html, '>Concept, not built</span>'));
        $this->assertSame(4, preg_match_all('/role="img"[^>]*aria-label="Concept, not built: /', $html));
    }

    public function test_it_names_no_partnership_or_endorsement(): void
    {
        $text = $this->text();
        $this->assertStringContainsString(self::NO_PARTNER_SENTENCE, $text);

        $rest = str_replace(self::NO_PARTNER_SENTENCE, '', $text);
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:we|local goods)\b[^.]{0,40}\b(?:partner(?:ed|s|ing)?|work(?:s|ed|ing)?|collaborat\w*|team(?:s|ed|ing)? up)\b[^.]{0,10}\bwith\b/i',
            $rest,
        );
        $this->assertDoesNotMatchRegularExpression('/endorse(?:d|s|ment)? by|in partnership with|official partner/i', $rest);
        $this->assertStringContainsString('Naming a company on this page is not a relationship with it.', $text);
        // The district and the director are never named.
        $this->assertStringContainsString('feedback from a business improvement district director.', $text);
        $this->assertStringNotContainsString('Golden Triangle', $text);
    }

    public function test_it_is_drawn_without_outside_images_or_logos(): void
    {
        $html = $this->main();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertDoesNotMatchRegularExpression('~url\(\s*[\'"]?https?:~i', $html);
        $this->assertDoesNotMatchRegularExpression('~<(?:script|link|iframe|video|audio|source)\b~i', $html);
        $this->assertDoesNotMatchRegularExpression('~\bsrc\s*=~i', $html);
        // Companies appear as plain text only.
        $this->assertStringContainsString('DoorDash Drive or Uber Direct', $html);
    }

    public function test_judge_home_links_to_it_for_the_public_and_a_named_judge(): void
    {
        $this->seed(DatabaseSeeder::class);
        DemoAccountSeeder::seedPublicReviewer(config('demo.accounts.judge'));
        $public = User::where('reviewer_type', ReviewerType::Public)->sole();
        $named = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::ContestJudge]);

        foreach ([$public, $named] as $judge) {
            $this->actingAs($judge)->get('/judge')->assertOk()
                ->assertSee('href="'.route('coming-next').'"', false)
                ->assertSee('Coming next');
        }
    }

    public function test_readme_links_to_it_from_where_its_going(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        $section = explode("### Where it's going", $readme, 2)[1] ?? '';
        $this->assertStringContainsString('/coming-next', explode("\n###", $section)[0]);
    }
}
