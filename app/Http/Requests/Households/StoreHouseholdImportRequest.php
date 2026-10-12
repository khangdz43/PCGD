<?php

namespace App\Http\Requests\Households;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHouseholdImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $year = now()->month >= 8 ? now()->year : now()->year - 1;

        return [
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:20480'],
            'school_year' => ['required', Rule::in(["{$year}-".($year + 1)])],
            'province_code' => ['required', 'exists:provinces,code'],
            'commune_code' => [
                'required',
                Rule::exists('communes', 'code')->where('province_code', $this->input('province_code')),
            ],
            'village_code' => [
                'required',
                Rule::exists('villages', 'code')->where('commune_code', $this->input('commune_code')),
            ],
        ];
    }
}
