<?php

namespace App\Http\Requests\Client;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('tier')) {
            return;
        }

        $tier = $this->input('tier');
        if ($tier === null || $tier === '') {
            $this->merge(['tier' => null]);

            return;
        }

        $this->merge(['tier' => strtolower(trim((string) $tier))]);
    }

    public function rules(): array
    {
        $contactUserId = $this->route('client')?->contact_user_id;

        return [
            'company_name'  => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'contact_name'  => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'contact_email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($contactUserId),
            ],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'phone_country' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'tier' => ['sometimes', 'nullable', 'string', Rule::in(Client::TIERS)],
        ];
    }
}
