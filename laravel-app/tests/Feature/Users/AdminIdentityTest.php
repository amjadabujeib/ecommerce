<?php

namespace Tests\Feature\Users;

use App\Modules\Users\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_case_admin_configuration_is_normalized_for_login(): void
    {
        config(['store.admin.email' => ' Admin@Example.test ', 'store.admin.password' => 'ConfiguredOnly123!']);
        $this->seed(AdminUserSeeder::class);
        $this->postJson(route('login'), ['email' => 'Admin@Example.test', 'password' => 'ConfiguredOnly123!'])->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'admin@example.test', 'role' => 'admin']);
    }

    public function test_legacy_mixed_case_admin_is_updated_without_duplicating_its_username(): void
    {
        $admin = User::factory()->admin()->create(['username' => config('store.admin.username'), 'email' => 'Admin@Example.test']);
        config(['store.admin.email' => 'admin@example.test', 'store.admin.password' => 'ConfiguredOnly123!']);
        $this->seed(AdminUserSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'email' => 'admin@example.test']);
        $this->postJson(route('login'), ['email' => 'ADMIN@EXAMPLE.TEST', 'password' => 'ConfiguredOnly123!'])->assertOk();
    }

    public function test_changing_the_configured_admin_email_preserves_the_existing_admin_identity(): void
    {
        $admin = User::factory()->admin()->create(['username' => config('store.admin.username'), 'email' => 'old@example.test']);
        config(['store.admin.email' => ' New@Example.test ', 'store.admin.password' => 'ConfiguredOnly123!']);
        $this->seed(AdminUserSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'email' => 'new@example.test']);
        $this->postJson(route('login'), ['email' => 'new@example.test', 'password' => 'ConfiguredOnly123!'])->assertOk();
    }

    public function test_ambiguous_legacy_email_identities_are_not_silently_merged_or_promoted(): void
    {
        User::factory()->admin()->create(['email' => 'Admin@Example.test']);
        $customer = User::factory()->create(['email' => 'admin@example.test']);
        config(['store.admin.email' => 'admin@example.test']);
        try {
            $this->seed(AdminUserSeeder::class);
            $this->fail('Ambiguous legacy identities must require explicit resolution');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Multiple accounts', $error->getMessage());
        }
        $this->assertSame('customer', $customer->fresh()->role->value);
        $this->assertDatabaseCount('users', 2);
    }
}
