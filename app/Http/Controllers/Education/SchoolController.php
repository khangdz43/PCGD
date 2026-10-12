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

        return view('schools.index', compact('schools', 'provinces'));
    }

    public function create(Request $request)
    {
        $school = new School;
        $provinces = $this->schools->provincesFor($request->user());

        return view('schools.create', compact('school', 'provinces'));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request, true));
        $this->schools->create($request->user(), $data);

        return redirect()->to(route('schools.index', [], false))
            ->with('success', 'Thêm trường học thành công.');
    }

    public function edit(int $id, Request $request)
    {
        $school = $this->schools->findAccessible($request->user(), $id);
        $provinces = $this->schools->provincesFor($request->user());

        return view('schools.edit', compact('school', 'provinces'));
    }

    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $school = $this->schools->findAccessible($user, $id);
        $data = $request->validate($this->rules($request));
        $this->schools->update($user, $school, $data);

        return redirect()->to(route('schools.index', [], false))
            ->with('success', 'Cập nhật trường học thành công.');
    }

    public function destroy(int $id, Request $request)
    {
        $school = $this->schools->findAccessible($request->user(), $id);
        $this->schools->delete($school);

        return redirect()->to(route('schools.index', [], false))
            ->with('success', 'Đã xóa trường học.');
    }

    private function rules(Request $request, bool $creating = false): array
    {
        $rules = [
            'name' => 'required|string|max:255',
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

        $rules['code'] = 'required|string|max:50|unique:schools,code';

        return $rules;
    }
}
