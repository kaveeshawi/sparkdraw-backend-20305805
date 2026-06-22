<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'           => ['sometimes', 'string', 'max:255'],
            'description'     => ['sometimes', 'nullable', 'string'],
            'priority'        => ['sometimes', 'in:low,medium,high'],
            'estimated_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'deadline'        => ['sometimes', 'nullable', 'date'],
            'assignee_id'     => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'milestone_id'    => ['sometimes', 'nullable', 'integer', 'exists:milestones,id'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $updatable = ['title', 'description', 'priority', 'estimated_hours', 'deadline', 'assignee_id', 'milestone_id'];
            $hasField  = collect($updatable)->contains(fn($f) => $this->has($f));

            if (!$hasField) {
                $v->errors()->add('general', 'At least one field must be provided to update.');
            }
        });
    }
}
