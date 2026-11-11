<?php

namespace Tests\Feature\Users;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuthEmailValidationTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidEmails(): array
    {
        return [[[]], [['person@example.test']], [123], [true]];
    }

    #[DataProvider('invalidEmails')]
    public function test_malformed_email_reaches_validation_without_limiter_type_errors(mixed $email): void
    {
        foreach (['login', 'register'] as $route) {
            $this->postJson(route($route), [
                'username' => 'invalidemail', 'email' => $email,
                'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!',
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->assertDatabaseCount('users', 0);
    }
}
