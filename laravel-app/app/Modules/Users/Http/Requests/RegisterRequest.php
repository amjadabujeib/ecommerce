<?php

namespace App\Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9 ]+$/', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed', function ($attribute, $value, $fail): void {
                if (! is_string($value)) {
                    return;
                }
                if (str_contains($value, "\0")) {
                    $fail('The password must not contain NUL characters.');
                }
                if (config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes for bcrypt.');
                }
            }],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => is_string($this->input('username'))
                ? trim($this->input('username'))
                : $this->input('username'),
            'email' => is_string($this->input('email'))
                ? Str::lower(trim($this->input('email')))
                : $this->input('email'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'Usernames may only contain letters, numbers and spaces.',
        ];
    }
}
