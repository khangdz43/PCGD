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
                || in_array($user->roleScopeLevel(), ['global', 'province', 'commune'], true));
    }

    public function rules(): array
    {
        return [
            'source_truong_ids' => ['required', 'array', 'min:1'],
            'source_truong_ids.*' => [
                'required',
                'string',
                'max:50',
                'distinct',
                Rule::notIn([$this->input('target_truong_id')]),
                Rule::exists('schools', 'code')->whereNull('deleted_at'),
            ],
            'target_truong_id' => [
                'required',
                'string',
                'max:50',
                Rule::exists('schools', 'code')->whereNull('deleted_at'),
            ],
            'qd_sap_nhap' => ['nullable', 'string', 'max:100'],
        ];
    }
}
