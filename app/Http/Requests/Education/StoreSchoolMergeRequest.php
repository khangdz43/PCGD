<?php

namespace App\Http\Requests\Education;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSchoolMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ($user->hasRole(Role::SYSTEM_ADMIN)
                || in_array($user->roleScopeLevel(), ['province', 'commune'], true));
    }

    public function rules(): array
    {
        return [
            'source_truong_ids' => ['required', 'array', 'min:1'],
            'source_truong_ids.*' => [
                'required',
                'string',
                'max:20',
                'distinct',
                Rule::notIn([$this->input('target_truong_id')]),
                Rule::exists('schools', 'code'),
            ],
            'target_truong_id' => ['required', 'string', 'max:20', Rule::exists('schools', 'code')],
            'qd_sap_nhap' => ['nullable', 'string', 'max:100'],
        ];
    }
}
