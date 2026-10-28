<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\User;
use App\Services\Corrections\CorrectionDecisions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\TestCase;

/**
 * Corrections show people by initials only (ADR 0028, ADR 0014): the
 * employee on the merchant's screens and the queue, the admin who decided
 * everywhere. EmployeeNamePrivacyTest also sweeps every page with the seeded
 * corrections in place; this checks the correction screens directly.
 */
class CorrectionPrivacyTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SeedsOnAFixedDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_no_employee_or_admin_name_on_the_correction_screens(): void
    {
        $merchantUser = $this->signIn(Role::Merchant);
        $redemption = $this->cleared(1250);
        $this->refundRequest($redemption);
        $admin = User::factory()->role(Role::PlatformAdmin)->create(['name' => 'Harriet Quill-Admin']);
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($this->cleared(900, $redemption->employee)), $admin, 'Refund.', amountCents: 200);
        $name = $redemption->employee->display_name;

        $this->actingAs($merchantUser);
        $this->get(route('merchant.dashboard'))->assertOk()->assertDontSee($name)->assertSee($redemption->employee->initials());
        $this->get(route('merchant.redemptions.csv'))->assertOk()->assertDontSee($name);

        foreach ([User::factory()->role(Role::PlatformAdmin)->create(), User::factory()->role(Role::BidAdmin)->create()] as $viewer) {
            $this->actingAs($viewer);
            $this->get(route('bid-admin.corrections'))->assertOk()
                ->assertDontSee($name)->assertDontSee('Harriet Quill-Admin')
                ->assertSee('Employee '.$redemption->employee->initials())
                ->assertSee('H.Q.');
        }
    }

    public function test_the_employee_sees_their_own_correction_without_the_admins_name(): void
    {
        $employeeUser = $this->signIn(Role::Employee);
        $redemption = $this->cleared(1250, $employeeUser->employee);
        $admin = User::factory()->role(Role::PlatformAdmin)->create(['name' => 'Harriet Quill-Admin']);
        app(CorrectionDecisions::class)->reject($this->refundRequest($redemption), $admin, 'The receipt matches.');

        $this->actingAs($employeeUser);
        $this->get(route('employee.wallet'))->assertOk()->assertSee('“The receipt matches.”', false)->assertDontSee('Harriet');
        $this->assertSame(1, Correction::count() - 2, 'Besides the two seeded examples');
    }
}
