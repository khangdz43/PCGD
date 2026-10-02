<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\Province;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolController extends Controller
{
    public function index(Request $request)
    {
        $query = School::with('province', 'commune');

        if ($request->filled('q')) {
            $search = $request->string('q')->trim()->toString();
            $query->where(function ($builder) use ($search) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        if ($request->filled('province_code')) {
            $query->where('province_code', $request->input('province_code'));
        }

        if ($request->filled('commune_code')) {
            $query->where('commune_code', $request->input('commune_code'));
        }

        $schools = $query->orderBy('name')->paginate(15)->withQueryString();
        $provinces = Province::orderBy('name')->get(['code', 'name']);

        return view('schools.index', compact('schools', 'provinces'));
    }

    public function create()
    {
        $school = new School();
        $provinces = Province::orderBy('name')->get(['code', 'name']);

        return view('schools.create', compact('school', 'provinces'));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request, true));

        School::create($data);

        return redirect()->to(route('schools.index', [], false))
            ->with('success', 'Thêm trường học thành công.');
    }



    public function edit(int $id)
    {
        $school = School::findOrFail($id);
        $provinces = Province::orderBy('name')->get(['code', 'name']);

        return view('schools.edit', compact('school', 'provinces'));
    }

    public function update(Request $request, int $id)
    {
        $school = School::findOrFail($id);
        $school->update($request->validate($this->rules($request)));

        return redirect()->to(route('schools.index', [], false))
            ->with('success', 'Cập nhật trường học thành công.');
    }

    public function destroy(int $id)
    {
        School::findOrFail($id)->delete();

        return redirect()->to(route('schools.index', [], false))
            ->with('success', 'Đã xóa trường học.');
    }

    private function rules(Request $request, bool $creating = false): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'level' => ['nullable', Rule::in(['MN', 'TH', 'THCS'])],
            'province_code' => 'nullable|exists:provinces,code',
            'commune_code' => [
                'nullable',
                Rule::exists('communes', 'code')->where('province_code', $request->input('province_code')),
            ],
        ];

        if (!$creating) {
            return $rules;
        }

        $rules['code'] = 'required|string|max:50|unique:schools,code';

        return $rules;
    }
}
