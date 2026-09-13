<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'min:2', 'max:200'],
            'client_id'       => ['required', 'integer', 'exists:clients,id'],
            'type'            => ['required', 'string', 'max:100'],
            'description'     => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status'          => ['sometimes', 'string', 'in:not_started,started,active,on_hold,completed'],
            'start_date'      => ['sometimes', 'nullable', 'date'],
            'end_date'        => ['required', 'date', 'after_or_equal:start_date'],
            'budget'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimated_hours' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'color'           => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'team_member_ids'   => ['sometimes', 'array'],
            'team_member_ids.*' => ['integer', 'exists:users,id'],
            'milestones'                 => ['sometimes', 'array', 'max:12'],
            'milestones.*.title'         => ['required_with:milestones', 'string', 'max:200'],
            'milestones.*.due_offset_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:730'],
            'milestones.*.description'   => ['sometimes', 'nullable', 'string', 'max:1000'],
            'milestones.*.tasks'         => ['sometimes', 'array', 'max:8'],
            'milestones.*.tasks.*.title' => ['required_with:milestones.*.tasks', 'string', 'max:200'],
            'milestones.*.tasks.*.priority' => ['sometimes', 'string', 'in:low,medium,high'],
            'milestones.*.tasks.*.estimated_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('start_date')) {
            $this->merge(['start_date' => now()->toDateString()]);
        }
    }
}
