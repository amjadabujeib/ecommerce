<?php

namespace Tests\Feature\Users;

use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_customer_and_ignores_a_supplied_role(): void
    {
        $response = $this->postJson(route('register'), [
            'username' => 'newcustomer',
            'email' => 'newcustomer@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role' => UserRole::Admin->value,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.role', UserRole::Customer->value)
            ->assertJsonPath('redirect_to', route('home'));

        $this->assertAuthenticated();

        $user = User::query()->where('email', 'newcustomer@example.com')->firstOrFail();

        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertTrue(Hash::check('StrongPass123!', $user->password));
    }

    public function test_registration_validates_unique_username_and_email(): void
    {
        User::factory()->create([
            'username' => 'taken',
            'email' => 'taken@example.com',
        ]);

        $this->postJson(route('register'), [
            'username' => 'taken',
            'email' => 'taken@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'email']);
    }

    public function test_admin_is_redirected_to_the_admin_dashboard_after_login(): void
    {
        $admin = User::factory()->admin()->create([
            'password' => 'StrongPass123!',
        ]);

        $this->postJson(route('login'), [
            'email' => $admin->email,
            'password' => 'StrongPass123!',
        ])
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::Admin->value)
            ->assertJsonPath('redirect_to', route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_customer_is_redirected_to_the_storefront_after_login(): void
    {
        $customer = User::factory()->create([
            'password' => 'StrongPass123!',
        ]);

        $this->postJson(route('login'), [
            'email' => $customer->email,
            'password' => 'StrongPass123!',
        ])
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::Customer->value)
            ->assertJsonPath('redirect_to', route('home'));
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $customer = User::factory()->create();

        $this->postJson(route('login'), [
            'email' => $customer->email,
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = JWTAuth::fromUser(User::factory()->create());

        $this->withToken($token)
            ->postJson(route('logout'))
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson(route('me'))
            ->assertUnauthorized();
    }
}
