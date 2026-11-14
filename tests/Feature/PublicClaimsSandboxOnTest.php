<?php

namespace Tests\Feature;

use App\Services\Sandbox\SandboxRoutes;
use Illuminate\Support\Facades\Route;
use ReflectionClassConstant;
use Tests\Concerns\UsesSandboxes;

/**
 * Every public claims check again with "Try it yourself" switched on
 * (ADR 0045): the landing page and judge Home show the button and /sandbox
 * opens. Then the same wording checks on every sandbox page, as each of the
 * five sandbox roles.
 */
class PublicClaimsSandboxOnTest extends PublicClaimsTest
{
    use UsesSandboxes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandboxes();
    }

    private static function rule(string $name): string
    {
        return (new ReflectionClassConstant(PublicClaimsTest::class, $name))->getValue();
    }

    public function test_the_sandbox_explainer_is_swept_with_the_public_pages(): void
    {
        $this->get('/')->assertSee('Try it yourself');
        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->get(route('sandbox.show'))->assertOk()->getContent())));

        foreach (['OVERCLAIM', 'STRIPE_MISSING_OR_LIVE', 'BUSINESS_MODEL_NUMBER'] as $rule) {
            $this->assertDoesNotMatchRegularExpression(self::rule($rule), $text, "/sandbox: {$rule}");
        }
    }

    public function test_the_same_wording_rules_hold_on_every_sandbox_page_as_every_role(): void
    {
        $this->startSandbox();
        $pages = collect(Route::getRoutes())->filter(fn ($r) => $r->getName() !== null && in_array('GET', $r->methods(), true)
            && ! str_contains($r->uri(), '{') && SandboxRoutes::allows($r->getName()) && ! str_ends_with($r->uri(), '.csv'))
            ->map(fn ($r) => '/'.ltrim($r->uri(), '/'));
        $preview = self::rule('PLANNED_FEE_PREVIEW');
        $checked = 0;

        foreach (array_keys(SandboxRoutes::ROLES) as $role) {
            $this->switchRole($role);
            foreach ($pages as $uri) {
                $response = $this->sandboxGet($uri);
                if ($response->status() !== 200) {
                    continue;
                }
                $html = $response->getContent();
                // The labelled planned-fee preview is allowed on the employer page, as on the live one.
                $html = preg_replace_callback($preview, fn ($m) => str_contains($m[0], self::rule('PROJECTION_LABEL')) ? '' : $m[0], $html);
                $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
                $where = "{$uri} in sandbox as {$role}";

                $this->assertStringContainsString('Sandbox: your own copy. Nothing here touches the live demo or Stripe. Deleted after 24 hours.', $text, $where);
                foreach (['OVERCLAIM', 'STRIPE_MISSING_OR_LIVE', 'BUSINESS_MODEL_NUMBER'] as $rule) {
                    $this->assertDoesNotMatchRegularExpression(self::rule($rule), $text, "{$where}: {$rule}");
                }
                if (preg_match(self::rule('MULTIPLIER'), $text) && ! preg_match('/3x (the|their|her|his) (usual|median)/i', $text)) {
                    $this->assertStringContainsString(self::rule('LABEL'), $text, $where);
                }
                $checked++;
            }
        }

        $this->assertGreaterThan(15, $checked);
    }
}
