<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            // Deliberately no strength rules here: login must not tell an
            // attacker what the password policy is, and legacy passwords that
            // predate a policy change still have to be accepted.
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * Key used by the login rate limiter: per email *and* per IP, so one
     * noisy client cannot lock a victim out of their own account.
     */
    public function throttleKey(): string
    {
        return sha1(strtolower((string) $this->input('email')).'|'.$this->ip());
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => (string) $this->string('email'),
            'password' => (string) $this->string('password'),
        ];
    }
}
