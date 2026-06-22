<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'           => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'priority'        => ['nullable', 'in:low,medium,high'],
            'estimated_hours' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'deadline'        => ['nullable', 'date', 'after_or_equal:today'],
            'assignee_id'     => ['nullable', 'integer', 'exists:users,id'],
            'milestone_id'    => ['nullable', 'integer', 'exists:milestones,id'],
        ];
    }
}
