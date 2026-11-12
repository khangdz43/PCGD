<?php

namespace App\Http\Requests\Geography;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeVillagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->roleScopeLevel() === 'commune'
            && filled($user->commune_code);
    }

    public function rules(): array
    {
        $communeCode = $this->user()->commune_code;

        return [
            'source_codes' => ['required', 'array', 'min:1'],
            'source_codes.*' => [
                'required',
                'string',
                'max:20',
                'distinct',
                Rule::notIn([$this->input('target_code')]),
                Rule::exists('villages', 'code')->where('commune_code', $communeCode),
            ],
            'target_code' => [
                'required',
                'string',
                'max:20',
                Rule::exists('villages', 'code')->where('commune_code', $communeCode),
            ],
        ];
    }
}
