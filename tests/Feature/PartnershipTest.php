<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Bid;
use App\Models\District;
use App\Models\Partnership;
use App\Models\PartnershipChange;
use App\Models\User;
use App\Services\Districts\PartnershipSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * BID partnerships (ADR 0031): one active exclusive partnership per district
 * (service and database), platform admin only, against the reviewed
 * version, status forward only, an append-only change log, and a seeded
 * demo partnership labelled as one.
 */
class PartnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function settings(): PartnershipSettings
    {
        return app(PartnershipSettings::class);
    }

    private function platformAdmin(): User
    {
        return User::factory()->role(Role::PlatformAdmin)->create();
    }

    private function demo(): Partnership
    {
        return Partnership::sole();
    }

    /** @return array<string, mixed> */
    private function terms(array $overrides = []): array
    {
        return [...$this->demo()->terms(), ...$overrides];
    }

    public function test_the_seed_has_one_demo_partnership_labelled_as_such(): void
    {
        $partnership = $this->demo();

        $this->assertTrue($partnership->is_demo);
        $this->assertTrue($partnership->exclusive);
        $this->assertSame(Partnership::STATUS_ACTIVE, $partnership->status);
        $this->assertSame(0, $partnership->revenue_share_bps);
        $this->assertSame(District::default()->id, $partnership->exclusive_active_key);
        $this->assertStringContainsString('employee data stays private', mb_strtolower($partnership->data_terms));
        $this->assertStringContainsString('Not an actual agreement', $partnership->changes()->sole()->reason);
        $this->assertSame('Demo partnership — not an actual agreement.', Partnership::DEMO_LABEL);
    }

    public function test_a_second_active_exclusive_partnership_in_the_district_is_refused(): void
    {
        $this->expectExceptionMessage(PartnershipSettings::ALREADY_EXCLUSIVE);

        $this->settings()->create($this->platformAdmin(), Bid::firstOrFail(), District::default(), $this->terms(), 'A second one');
    }

    public function test_the_database_refuses_a_second_exclusive_active_key_on_its_own(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('partnerships')->insert([
            'bid_id' => Bid::value('id'), 'district_id' => District::default()->id, 'exclusive' => true, 'starts_on' => '2026-11-01',
            'revenue_share_bps' => 0, 'data_terms' => 'x', 'status' => 'active', 'exclusive_active_key' => District::default()->id,
        ]);
    }

    public function test_the_model_derives_the_key_so_a_direct_save_cannot_slip_past(): void
    {
        $second = new Partnership(['bid_id' => Bid::value('id'), 'district_id' => District::default()->id, 'exclusive' => true,
            'starts_on' => '2026-11-01', 'revenue_share_bps' => 0, 'data_terms' => 'x', 'status' => Partnership::STATUS_DRAFT]);
        $second->save();
        $this->assertNull($second->exclusive_active_key, 'a draft holds no key');

        $this->expectException(UniqueConstraintViolationException::class);
        $second->update(['status' => Partnership::STATUS_ACTIVE]);
    }

    public function test_non_exclusive_or_ended_partnerships_can_sit_beside_the_exclusive_one(): void
    {
        $admin = $this->platformAdmin();
        [$openTerms, $endedTerms] = [$this->terms(['exclusive' => false]), $this->terms(['status' => Partnership::STATUS_ENDED, 'ends_on' => '2026-10-31'])];
        $open = $this->settings()->create($admin, Bid::firstOrFail(), District::default(), $openTerms, 'Non-exclusive');
        $ended = $this->settings()->create($admin, Bid::firstOrFail(), District::default(), $endedTerms, 'Old one');

        $this->assertNull($open->exclusive_active_key);
        $this->assertNull($ended->exclusive_active_key);
    }

    public function test_ending_the_exclusive_one_frees_the_district(): void
    {
        $admin = $this->platformAdmin();
        $this->settings()->change($admin, $this->demo(), $this->terms(['status' => Partnership::STATUS_ENDED, 'ends_on' => '2026-10-31']), 'Ended', $this->demo()->latestChangeId());

        $next = $this->settings()->create($admin, Bid::firstOrFail(), District::default(), $this->terms(['starts_on' => '2026-11-01', 'ends_on' => null, 'status' => Partnership::STATUS_DRAFT]), 'New term');
        $next = $this->settings()->change($admin, $next, [...$next->terms(), 'status' => Partnership::STATUS_ACTIVE], 'Signed', $next->latestChangeId())->partnership;

        $this->assertSame(District::default()->id, $next->exclusive_active_key);
    }

    public function test_only_the_platform_admin_changes_a_partnership(): void
    {
        foreach ([Role::BidAdmin, Role::Judge, Role::EmployerAdmin] as $role) {
            try {
                $this->settings()->change(User::factory()->role($role)->create(), $this->demo(), $this->terms(['revenue_share_bps' => 100]), 'Try', $this->demo()->latestChangeId());
                $this->fail("{$role->value} changed the partnership.");
            } catch (LogicException) {
            }
        }

        $this->assertSame(0, $this->demo()->fresh()->revenue_share_bps);
    }

    public function test_a_change_against_an_older_version_is_refused(): void
    {
        $admin = $this->platformAdmin();
        $reviewed = $this->demo()->latestChangeId();
        $this->settings()->change($admin, $this->demo(), $this->terms(['revenue_share_bps' => 100]), 'First', $reviewed);

        $this->expectExceptionMessage('changed since you reviewed this');
        $this->settings()->change($admin, $this->demo()->fresh(), $this->terms(['revenue_share_bps' => 200]), 'Second', $reviewed);
    }

    public function test_status_only_moves_forward_and_each_change_is_logged(): void
    {
        $admin = $this->platformAdmin();
        $change = $this->settings()->change($admin, $this->demo(), $this->terms(['status' => Partnership::STATUS_ENDED, 'ends_on' => '2026-12-31']), 'Ended for the test', $this->demo()->latestChangeId());

        $this->assertSame(['status', 'ends_on'], $change->changedTerms());
        $this->assertSame('active', $change->before['status']);
        $this->assertSame($admin->id, $change->changed_by_user_id);

        foreach ([Partnership::STATUS_ACTIVE, Partnership::STATUS_DRAFT] as $back) {
            try {
                $this->settings()->change($admin, $this->demo()->fresh(), $this->terms(['status' => $back]), 'Back', $this->demo()->fresh()->latestChangeId());
                $this->fail("Moved from ended back to {$back}.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("can't go from ended back to {$back}", $e->getMessage());
            }
        }

        $this->assertSame(2, PartnershipChange::count());
    }

    public function test_terms_are_checked(): void
    {
        $admin = $this->platformAdmin();
        $cases = [
            [['revenue_share_bps' => 5001], 'from 0% to 50%'],
            [['revenue_share_bps' => -1], 'from 0% to 50%'],
            [['ends_on' => '2026-09-30'], 'before the start date'],
            [['data_terms' => ' '], 'data terms'],
            [['status' => 'paused'], 'draft, active or ended'],
        ];

        foreach ($cases as [$overrides, $message]) {
            try {
                $this->settings()->change($admin, $this->demo(), $this->terms($overrides), 'Check', $this->demo()->latestChangeId());
                $this->fail('Accepted '.json_encode($overrides));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->expectExceptionMessage('Write a reason');
        $this->settings()->change($admin, $this->demo(), $this->terms(['revenue_share_bps' => 100]), '  ', $this->demo()->latestChangeId());
    }

    public function test_the_change_log_is_append_only(): void
    {
        $change = $this->demo()->changes()->sole();

        try {
            $change->update(['reason' => 'Rewritten']);
            $this->fail('A partnership change was edited.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $change->delete();
    }
}
