<?php

namespace App\Http\Controllers\Education;

use App\Http\Controllers\Controller;
use App\Models\Province;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolController extends Controller
{
    public function getByProvince(string $provinceCode, Request $request)
    {
        $query = School::query()->where('province_code', $provinceCode);
        if ($request->filled('commune_code')) {
            $query->where('commune_code', $request->input('commune_code'));
        }

        $this->constrainLookupToUser($query, $request, $provinceCode);

        $schools = $query->orderBy('name')->get(['code', 'name']);

        return response()->json($schools->map(fn (School $school): array => [
            'code' => $school->code,
            'name' => $school->display_name,
        ]));
    }

    public function index(Request $request)
    {
        $query = School::with('province', 'commune');
        $this->constrainToUserArea($query, $request->user());

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
        $provinces = $this->provincesFor($request->user());

        return view('schools.index', compact('schools', 'provinces'));
    }

    public function create(Request $request)
    {
        $school = new School;
        $provinces = $this->provincesFor($request->user());

        return view('schools.create', compact('school', 'provinces'));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request, true));
        $this->assertSchoolScope($request->user(), $data);

        School::create($data);

        return redirect()->to(route('schools.index', [], false))
            ->with('success', 'Thêm trường học thành công.');
    }

    public function edit(int $id, Request $request)
    {
        $school = School::findOrFail($id);
        $this->assertSchoolAccess($request->user(), $school);
        $provinces = $this->provincesFor($request->user());

        return view('schools.edit', compact('school', 'provinces'));
    }

    public function update(Request $request, int $id)
    {
        $school = School::findOrFail($id);
        $this->assertSchoolAccess($request->user(), $school);
        $data = $request->validate($this->rules($request));
        $this->assertSchoolScope($request->user(), $data);
        $school->update($data);

        return redirect()->to(route('schools.index', [], false))
            ->with('success', 'Cập nhật trường học thành công.');
    }

    public function destroy(int $id, Request $request)
    {
        $school = School::findOrFail($id);
        $this->assertSchoolAccess($request->user(), $school);
        $school->delete();

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

    private function constrainLookupToUser(Builder $query, Request $request, string $provinceCode): void
    {
        $user = $request->user();
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        if ($user->province_code !== $provinceCode) {
            $query->whereRaw('1 = 0');
        }

        if (in_array($user->roleScopeLevel(), ['commune', 'village'], true)) {
            $query->where('commune_code', $user->commune_code);
        }
    }

    private function provincesFor(User $user)
    {
        $query = Province::query()->orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $query->where('code', $user->province_code);
        }

        return $query->get(['code', 'name']);
    }

    private function constrainToUserArea(Builder $query, User $user): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        match ($user->roleScopeLevel()) {
            'province' => $query->where('province_code', $user->province_code),
            'commune' => $query->where('commune_code', $user->commune_code),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function assertSchoolAccess(User $user, School $school): void
    {
        $this->assertSchoolScope($user, [
            'province_code' => $school->province_code,
            'commune_code' => $school->commune_code,
        ]);
    }

    private function assertSchoolScope(User $user, array $scope): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        $allowed = match ($user->roleScopeLevel()) {
            'province' => $user->province_code !== null && $scope['province_code'] === $user->province_code,
            'commune' => $user->province_code !== null
                && $user->commune_code !== null
                && $scope['province_code'] === $user->province_code
                && $scope['commune_code'] === $user->commune_code,
            default => false,
        };

        abort_unless($allowed, 403);
    }
}
