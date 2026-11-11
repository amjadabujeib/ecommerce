<?php

namespace Tests\Feature\Users;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PasswordValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_nul_and_bcrypt_byte_overflow_are_validation_errors(): void
    {
        foreach (["ReviewOnly\0Password123!", str_repeat('x', 73), str_repeat('é', 37)] as $index => $password) {
            $this->postJson(route('register'), [
                'username' => 'invalid'.$index, 'email' => 'invalid'.$index.'@example.test',
                'password' => $password, 'password_confirmation' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_bcrypt_boundary_password_can_register_and_sign_in(): void
    {
        $password = str_repeat('é', 36);
        $this->postJson(route('register'), [
            'username' => 'boundary', 'email' => 'boundary@example.test',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertCreated();
        $this->postJson(route('login'), ['email' => 'boundary@example.test', 'password' => $password])->assertOk();
    }
}
