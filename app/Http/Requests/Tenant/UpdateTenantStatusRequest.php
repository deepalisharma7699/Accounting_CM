<?php

namespace App\Http\Requests\Tenant;

use App\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(TenantStatus::class)],
        ];
    }

    public function status(): TenantStatus
    {
        return TenantStatus::from((string) $this->string('status'));
    }
}
