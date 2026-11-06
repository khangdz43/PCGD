<?php

namespace App\Http\Requests\Households;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'file' => ['required_without:files', 'prohibits:files', 'file', 'mimes:xls,xlsx', 'max:20480'],
            'files' => ['required_without:file', 'prohibits:file', 'array', 'min:1', 'max:5'],
            'files.*' => ['required', 'file', 'mimes:xls,xlsx', 'max:20480'],
            'school_year' => ['required', Rule::in(["{$year}-" . ($year + 1)])],
            'province_code' => ['required', 'exists:provinces,code'],
            'commune_code' => [
                'required',
                Rule::exists('communes', 'code')->where('province_code', $this->input('province_code')),
            ],
            'village_code' => [
                'required',
                Rule::exists('villages', 'code')
                    ->where('commune_code', $this->input('commune_code'))
                    ->whereNull('merged_into_code')
                    ->whereNull('intake_paused_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $files = $this->file('files', []);
            if (! is_array($files)) {
                return;
            }

            $totalSize = array_sum(array_map(
                static fn($file): int => $file instanceof UploadedFile ? ($file->getSize() ?: 0) : 0,
                $files,
            ));

            if ($totalSize > 35 * 1024 * 1024) {
                $validator->errors()->add('files', 'Tổng dung lượng các tệp không được vượt quá 35 MB.');
            }
        });
    }
}
