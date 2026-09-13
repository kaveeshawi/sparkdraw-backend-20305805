<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $isAdmin = $user && $user->role === 'admin';

        return [
            'name'             => ['sometimes', 'string', 'min:2', 'max:100'],
            'email'            => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user?->id)],
            'role'             => $isAdmin
                ? ['prohibited']
                : ['sometimes', Rule::in(['pm', 'member'])],
            'custom_role_id'   => $isAdmin
                ? ['prohibited']
                : [
                    'sometimes', 'nullable',
                    Rule::exists('custom_roles', 'id')->where(function ($q) use ($user) {
                        $q->where('agency_id', $this->user()->agency_id)
                            ->where('base_role', $this->input('role', $user?->role));
                    }),
                ],
            'department'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'employment_type'  => ['sometimes', 'nullable', Rule::in(['full_time', 'part_time', 'contractor'])],
            'phone'            => ['sometimes', 'nullable', 'string', 'max:40'],
            'job_title'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile_meta'     => ['sometimes', 'nullable', 'array'],
            'profile_meta.first_name'     => ['sometimes', 'nullable', 'string', 'max:80'],
            'profile_meta.last_name'      => ['sometimes', 'nullable', 'string', 'max:80'],
            'profile_meta.address'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile_meta.birthday'       => ['sometimes', 'nullable', 'date'],
            'profile_meta.gender'         => ['sometimes', 'nullable', 'string', 'max:30'],
            'profile_meta.start_date'     => ['sometimes', 'nullable', 'date'],
            'profile_meta.work_location'  => ['sometimes', 'nullable', 'string', 'max:30'],
            'profile_meta.employee_id'    => ['sometimes', 'nullable', 'string', 'max:40'],
            'profile_meta.phone_country'  => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }
}
