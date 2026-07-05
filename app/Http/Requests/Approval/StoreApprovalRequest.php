<?php

namespace App\Http\Requests\Approval;

use Illuminate\Foundation\Http\FormRequest;

class StoreApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $projectId = $this->route('project')?->id;

        return [
            'deliverable_id' => [
                'required',
                'integer',
                // Asset must belong to this project and be marked as a deliverable
                \Illuminate\Validation\Rule::exists('assets', 'id')->where(function ($query) use ($projectId) {
                    $query->where('project_id', $projectId)
                          ->where('is_deliverable', true);
                }),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'deliverable_id.exists' => 'The selected asset is not a deliverable on this project.',
        ];
    }
}
