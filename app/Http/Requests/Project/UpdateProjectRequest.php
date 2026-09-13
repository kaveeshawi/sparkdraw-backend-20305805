<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['sometimes', 'string', 'min:2', 'max:200'],
            'type'            => ['sometimes', 'string', 'max:100'],
            'description'     => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status'          => ['sometimes', 'string', 'in:not_started,started,active,on_hold,completed,archived'],
            'priority'        => ['sometimes', 'string', 'in:low,medium,high'],
            'budget'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimated_hours' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_date'      => ['sometimes', 'date'],
            'end_date'        => ['sometimes', 'date', 'after_or_equal:start_date'],
            'color'           => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'client_id'       => ['sometimes', 'integer', 'exists:clients,id'],
            'team_member_ids'   => ['sometimes', 'array'],
            'team_member_ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $updatable = [
            'name', 'type', 'description', 'status', 'priority', 'budget',
            'estimated_hours', 'start_date', 'end_date', 'color', 'client_id',
            'team_member_ids',
        ];

        $validator->after(function ($v) use ($updatable) {
            if (empty(array_intersect(array_keys($this->all()), $updatable))) {
                $v->errors()->add('general', 'At least one field must be provided to update.');
            }
        });
    }
}
