<?php

namespace App\Http\Requests\Revision;

use Illuminate\Foundation\Http\FormRequest;

class StoreRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feedback_text' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
