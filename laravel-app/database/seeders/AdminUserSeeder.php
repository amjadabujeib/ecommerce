<?php

namespace Database\Seeders;

use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('store.admin.email');
        $username = config('store.admin.username');
        $password = config('store.admin.password');

        $admin = User::query()->firstOrNew(['email' => $email]);

        if (! $admin->exists) {
            if (blank($password)) {
                $password = Str::password(20);

                $this->command?->warn(
                    'ADMIN_PASSWORD was empty, so a random password was generated. '
                    .'Set it in .env to make the credential reproducible.',
                );
                $this->command?->warn("Admin password: {$password}");
            }

            $admin->password = $password;
        } elseif (filled($password)) {
            // Keep an existing administrator in sync with the configured password.
            $admin->password = $password;
        }

        $admin->forceFill([
            'username' => $username,
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ])->save();

        $this->command?->info("Administrator ready: {$admin->email}");
    }
}
