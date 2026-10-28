<?php

namespace App\Http\Controllers\Education;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Education\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolController extends Controller
{
    public function __construct(private readonly SchoolService $schools) {}

    public function getByProvince(string $provinceCode, Request $request)
    {
        $schools = $this->schools->schoolsForProvince(
            $provinceCode,
            $request->filled('commune_code') ? $request->input('commune_code') : null,
            $request->user()
        );

        return response()->json($schools);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $schools = $this->schools->listing($user, $request->only(['q', 'level', 'province_code', 'commune_code']));
        $provinces = $this->schools->provincesFor($user);

        return response()->json([
            'schools' => $schools,
            'provinces' => $provinces,
        ]);
    }

    public function create(Request $request)
    {
        $school = new School;
        $provinces = $this->schools->provincesFor($request->user());

        return response()->json(['school' => $school, 'provinces' => $provinces]);
    }

    public function show(int $id, Request $request)
    {
        return response()->json([
            'data' => $this->schools->findAccessible($request->user(), $id),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request, true), [
            'name.unique' => 'Tên trường cùng cấp học đã tồn tại trong đơn vị này.',
        ]);
        $this->schools->create($request->user(), $data);

        return response()->json(['message' => 'Thêm trường học thành công.'], 201);
    }

    public function edit(int $id, Request $request)
    {
        $school = $this->schools->findAccessible($request->user(), $id);
        $provinces = $this->schools->provincesFor($request->user());

        return response()->json(['school' => $school, 'provinces' => $provinces]);
    }

    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $school = $this->schools->findAccessible($user, $id);
        $data = $request->validate($this->rules($request, false, $school), [
            'name.unique' => 'Tên trường cùng cấp học đã tồn tại trong đơn vị này.',
        ]);
        $this->schools->update($user, $school, $data);

        return response()->json(['message' => 'Cập nhật trường học thành công.']);
    }

    public function destroy(int $id, Request $request)
    {
        $school = $this->schools->findAccessible($request->user(), $id);
        $this->schools->delete($school);

        return response()->json(['message' => 'Đã xóa trường học.']);
    }

    private function rules(Request $request, bool $creating = false, ?School $school = null): array
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('schools', 'name')
                    ->where('province_code', $request->input('province_code'))
                    ->where('commune_code', $request->input('commune_code'))
                    ->where('level', $request->input('level'))
                    ->ignore($school?->id),
            ],
            'level' => ['nullable', Rule::in(['MN', 'TH', 'THCS', 'THPT'])],
            'province_code' => 'nullable|exists:provinces,code',
            'commune_code' => [
                'nullable',
                Rule::exists('communes', 'code')->where('province_code', $request->input('province_code')),
            ],
        ];

        if (! $creating) {
            return $rules;
        }

        return $rules;
    }
}
