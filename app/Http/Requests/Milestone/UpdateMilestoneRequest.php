<?php

namespace App\Http\Requests\Milestone;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'    => ['sometimes', 'string', 'min:2', 'max:200'],
            'due_date' => ['sometimes', 'date'],
            'status'   => ['sometimes', 'string', 'in:pending,in_progress,completed'],
        ];
    }
}
