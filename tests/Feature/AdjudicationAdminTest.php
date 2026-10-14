<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AdjudicationSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The platform admin's page for building, publishing and closing the blind adjudication set. */
class AdjudicationAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_builds_edits_and_publishes_a_set(): void
    {
        $this->signIn(Role::PlatformAdmin);

        $this->get('/admin/adjudication')->assertOk()->assertSee('Build the draft');
        $this->post('/admin/adjudication')->assertRedirect('/admin/adjudication');

        $set = AdjudicationSet::draft();
        $this->get('/admin/adjudication')->assertOk()
            ->assertSee('Draft: version 1')
            ->assertSee('Holdout 3')
            ->assertSee('Blind controls');

        $case = $set->cases()->firstOrFail();
        $this->delete("/admin/adjudication/cases/{$case->id}")->assertRedirect();
        $this->assertModelMissing($case);

        $this->post('/admin/adjudication/cases', ['case_id' => $case->case_id])->assertRedirect('/admin/adjudication');
        $this->assertTrue($set->cases()->where('case_id', $case->case_id)->exists());

        $this->post('/admin/adjudication/publish')->assertRedirect('/admin/adjudication');
        $this->assertTrue($set->fresh()->isOpen());
        $this->get('/admin/adjudication')->assertOk()->assertSee('Voting open')->assertSee('Start version 2');
    }

    public function test_a_published_set_cannot_be_changed_through_the_page(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $this->post('/admin/adjudication');
        $this->post('/admin/adjudication/publish');
        $set = AdjudicationSet::current();
        $case = $set->cases()->firstOrFail();

        $this->delete("/admin/adjudication/cases/{$case->id}")->assertStatus(409);
        $this->post('/admin/adjudication/cases', ['case_id' => 'FE-01'])->assertNotFound();
        $this->post('/admin/adjudication/publish')->assertNotFound();
        $this->assertModelExists($case);
    }

    public function test_closing_ends_voting(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $this->post('/admin/adjudication');
        $this->post('/admin/adjudication/publish');

        $this->post('/admin/adjudication/close')->assertRedirect('/admin/adjudication');
        $this->assertTrue(AdjudicationSet::current()->isClosed());
        $this->post('/admin/adjudication/close')->assertNotFound();
    }

    public function test_adding_an_unknown_case_shows_an_error(): void
    {
        $this->signIn(Role::PlatformAdmin);
        $this->post('/admin/adjudication');

        $this->from('/admin/adjudication')->post('/admin/adjudication/cases', ['case_id' => 'FE-999'])
            ->assertRedirect('/admin/adjudication')
            ->assertSessionHasErrors('adjudication');
    }

    public function test_only_the_platform_admin_can_open_it(): void
    {
        foreach ([Role::BidAdmin, Role::EmployerAdmin, Role::Employee, Role::Merchant, Role::Judge] as $role) {
            $this->signIn($role);
            $this->get('/admin/adjudication')->assertForbidden();
            $this->post('/admin/adjudication')->assertForbidden();
        }

        $this->assertSame(0, AdjudicationSet::count());
    }
}
