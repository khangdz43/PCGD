<?php

namespace App\Http\Requests\Households;

use Illuminate\Foundation\Http\FormRequest;

class ExportHouseholdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'household_ids' => ['sometimes', 'array'],
            'household_ids.*' => ['integer', 'distinct', 'exists:households,id'],
            'school_code' => ['nullable', 'string'],
            'school_year' => ['nullable', 'string'],
        ];
    }
}
