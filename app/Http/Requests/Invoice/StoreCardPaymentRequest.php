<?php

namespace App\Http\Requests\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class StoreCardPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'card_name'   => ['required', 'string', 'max:100'],
            'card_number' => ['required', 'string', 'regex:/^\d{13,19}$/'],
            'expiry'      => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
            'cvv'         => ['required', 'string', 'regex:/^\d{3,4}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'card_number.regex' => 'Enter a valid card number (digits only).',
            'expiry.regex'      => 'Expiry must be MM/YY.',
            'cvv.regex'         => 'CVV must be 3 or 4 digits.',
        ];
    }
}
