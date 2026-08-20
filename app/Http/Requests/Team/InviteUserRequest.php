<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'min:2', 'max:100'],
            'email'            => ['required', 'email', 'unique:users,email'],
            'role'             => ['required', Rule::in(['pm', 'member'])],
            'department'       => ['required', 'string', 'max:100'],
            'employment_type'  => ['required', Rule::in(['full_time', 'part_time', 'contractor'])],
            'phone'            => ['nullable', 'string', 'max:40'],
            'job_title'        => ['nullable', 'string', 'max:100'],
            'send_email'       => ['sometimes', 'boolean'],
        ];
    }
}
