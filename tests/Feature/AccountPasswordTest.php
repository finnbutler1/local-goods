<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_change_their_own_password(): void
    {
        $admin = User::factory()->role(Role::BidAdmin)->create(['password' => 'old-password-123']);
        $this->actingAs($admin);

        $this->get('/account')->assertOk()->assertSee('Change your password')->assertSee($admin->email);

        $this->from('/account')->put('/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertRedirect('/account')->assertSessionHas('status', 'password-updated');

        $this->assertTrue(Hash::check('new-password-456', $admin->fresh()->password));
        $this->assertSame(Role::BidAdmin, $admin->fresh()->role);
    }

    public function test_the_current_password_is_required(): void
    {
        $admin = User::factory()->role(Role::BidAdmin)->create(['password' => 'old-password-123']);
        $this->actingAs($admin);

        $this->from('/account')->put('/user/password', [
            'current_password' => 'wrong',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertSessionHasErrors('current_password', errorBag: 'updatePassword');

        $this->assertTrue(Hash::check('old-password-123', $admin->fresh()->password));
    }

    public function test_the_password_form_only_touches_the_signed_in_user(): void
    {
        $admin = User::factory()->role(Role::BidAdmin)->create(['password' => 'old-password-123']);
        $other = User::factory()->role(Role::PlatformAdmin)->create(['password' => 'other-password-1']);
        $this->actingAs($admin);

        $this->put('/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
            'email' => $other->email,
            'role' => 'platform_admin',
        ]);

        $this->assertTrue(Hash::check('other-password-1', $other->fresh()->password));
        $this->assertSame(Role::BidAdmin, $admin->fresh()->role);
    }

    public function test_reviewers_have_no_password_to_change(): void
    {
        $judge = User::factory()->role(Role::Judge)->create(['password' => 'old-password-123']);
        $this->actingAs($judge);

        $this->get('/account')->assertOk()->assertDontSee('password', false);

        $this->put('/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('old-password-123', $judge->fresh()->password));
    }
}
