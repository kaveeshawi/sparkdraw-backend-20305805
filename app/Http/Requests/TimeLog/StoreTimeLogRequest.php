<?php

namespace App\Http\Requests\TimeLog;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimeLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hours'       => ['required', 'numeric', 'min:0.25', 'max:24'],
            'logged_date' => ['required', 'date'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ];
    }
}
