<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditPurchase;
use App\Models\DemoActivityAction;
use App\Models\Redemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\RunsDemoActivity;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Everything the demo activity generator made (ADR 0032) carries a small
 * "Demo activity" marker wherever a person-made one would look the same,
 * so a reader can tell at a glance what was generated and what a person
 * did. Person-made activity never carries it.
 */
class DemoActivityBadgeTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use RunsDemoActivity;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    private const MARK = 'data-demo-activity';

    protected function setUp(): void
    {
        parent::setUp();
        $this->startDemoActivity();
        $this->tickAt('2026-10-26 12:30');
    }

    public function test_generated_purchases_and_redemptions_are_marked_and_person_made_ones_are_not(): void
    {
        $generated = Redemption::whereIn('id', DemoActivityAction::whereNotNull('redemption_id')->pluck('redemption_id'))->get();
        $this->assertNotEmpty($generated);
        $this->assertTrue($generated->every->isGeneratedDemoActivity());
        $this->assertTrue(CreditPurchase::latest('id')->first()->isGeneratedDemoActivity());

        $byPerson = $this->cleared(1234);
        $this->assertFalse($byPerson->isGeneratedDemoActivity());
        $this->assertFalse(Redemption::where('scored_by', 'seed')->first()->isGeneratedDemoActivity());

        // A failed or skipped action never marks what it points at.
        DemoActivityAction::create(['day_id' => DemoActivityAction::value('day_id'), 'slot' => 'x', 'kind' => 'redemption', 'status' => 'failed', 'redemption_id' => $byPerson->id]);
        $this->assertFalse($byPerson->fresh()->isGeneratedDemoActivity());
    }

    public function test_the_marker_shows_on_each_page_that_lists_them(): void
    {
        $farragut = $this->farragut();
        $atFarragut = Redemption::where('merchant_id', $farragut->id)->whereIn('id', DemoActivityAction::pluck('redemption_id'))->exists();

        $this->signIn(Role::BidAdmin);
        $this->get(route('bid-admin.dashboard'))->assertOk()->assertSee(self::MARK, false);

        $this->signIn(Role::EmployerAdmin);
        $this->get(route('employer.dashboard'))->assertOk()->assertSee(self::MARK, false);

        if ($atFarragut) {
            $this->signIn(Role::Merchant);
            $this->get(route('merchant.dashboard'))->assertOk()->assertSee(self::MARK, false);
        }

        $order = Redemption::whereIn('id', DemoActivityAction::pluck('redemption_id'))->where('status', Redemption::STATUS_APPROVED)->firstOrFail();
        $this->withoutSeededCorrections();
        $this->refundRequest($order);
        $this->signIn(Role::PlatformAdmin);
        $this->get(route('bid-admin.corrections'))->assertOk()->assertSee(self::MARK, false);
    }

    public function test_nothing_is_marked_before_the_generator_runs(): void
    {
        DemoActivityAction::query()->delete();

        foreach ([[Role::BidAdmin, 'bid-admin.dashboard'], [Role::EmployerAdmin, 'employer.dashboard'], [Role::Merchant, 'merchant.dashboard'], [Role::Employee, 'employee.wallet']] as [$role, $route]) {
            $this->signIn($role);
            $this->get(route($route))->assertOk()->assertDontSee(self::MARK, false);
        }
    }
}
