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
            'custom_role_id'   => [
                'sometimes', 'nullable',
                Rule::exists('custom_roles', 'id')->where(function ($q) {
                    $q->where('agency_id', $this->user()->agency_id)
                        ->where('base_role', $this->input('role'));
                }),
            ],
            'department'       => ['required', 'string', 'max:100'],
            'employment_type'  => ['required', Rule::in(['full_time', 'part_time', 'contractor'])],
            'phone'            => ['nullable', 'string', 'max:40'],
            'job_title'        => ['nullable', 'string', 'max:100'],
            'send_email'       => ['sometimes', 'boolean'],
        ];
    }
}
