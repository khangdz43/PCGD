<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Models\Commune;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Models\Village;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VillageController extends Controller
{
    public function index(Request $request)
    {
        $query = Village::with('commune.province');
        $this->constrainToUserArea($query, $request->user());

        if ($request->filled('q')) {
            $search = $request->string('q')->trim()->toString();
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('province_code')) {
            $query->whereHas('commune', fn (Builder $builder) => $builder->where('province_code', $request->input('province_code')));
        }

        if ($request->filled('commune_code')) {
            $query->where('commune_code', $request->input('commune_code'));
        }

        $villages = $query->orderBy('name')->paginate(15)->withQueryString();
        $user = $request->user();
        $provincesQuery = Province::query()->orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $provincesQuery->where('code', $user->province_code);
        }
        $provinces = $provincesQuery->get(['code', 'name']);
        $provinceCode = $user->hasRole(Role::SYSTEM_ADMIN) ? '' : $user->province_code;
        $communeCode = in_array($user->roleScopeLevel(), ['commune', 'village'], true)
            ? $user->commune_code
            : '';

        return view('villages.index', compact('villages', 'provinces', 'provinceCode', 'communeCode'));
    }

    public function create(Request $request)
    {
        abort_if($request->user()->roleScopeLevel() === 'village', 403);

        $village = new Village;
        $provinces = $this->provincesFor($request->user());
        $user = $request->user();
        $provinceCode = $user->hasRole(Role::SYSTEM_ADMIN) ? '' : $user->province_code;
        $communeCode = in_array($user->roleScopeLevel(), ['commune', 'village'], true)
            ? $user->commune_code
            : '';

        return view('villages.create', compact('village', 'provinces', 'provinceCode', 'communeCode'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(array_merge([
            'code' => 'required|string|max:20|unique:villages,code',
            'name' => 'required|string|max:255',
        ], $this->locationRules($request)));
        $this->assertCanUseCommune($request->user(), $data['commune_code'], null);

        unset($data['province_code']);
        Village::create($data);

        return redirect()->to(route('villages.index', [], false))
            ->with('success', 'Thêm mới thôn/bản thành công!');
    }

    public function edit(string $code, Request $request)
    {
        $village = Village::with('commune')->where('code', $code)->firstOrFail();
        $this->assertCanUseCommune($request->user(), $village->commune_code, $village);
        $provinceCode = $village->commune->province_code;
        $provinces = $this->provincesFor($request->user());

        return view('villages.edit', compact('village', 'provinces', 'provinceCode'));
    }

    public function update(Request $request, string $code)
    {
        $village = Village::with('commune')->where('code', $code)->firstOrFail();
        $this->assertCanUseCommune($request->user(), $village->commune_code, $village);

        $data = $request->validate(array_merge([
            'name' => 'required|string|max:255',
        ], $this->locationRules($request)));
        $this->assertCanUseCommune($request->user(), $data['commune_code'], $village);

        unset($data['province_code']);
        $village->update($data);

        return redirect()->to(route('villages.index', [], false))
            ->with('success', 'Cập nhật thông tin thôn/bản thành công!');
    }

    public function destroy(Request $request, string $code)
    {
        $village = Village::with('commune')->where('code', $code)->firstOrFail();
        $this->assertCanUseCommune($request->user(), $village->commune_code, $village);
        $village->delete();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Đã xóa thôn/bản thành công!']);
        }

        return redirect()->to(route('villages.index', [], false))
            ->with('success', 'Đã xóa thôn/bản thành công!');
    }

    private function locationRules(Request $request): array
    {
        return [
            'province_code' => ['required', 'exists:provinces,code'],
            'commune_code' => [
                'required',
                Rule::exists('communes', 'code')->where('province_code', $request->input('province_code')),
            ],
        ];
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
            'province' => $query->whereHas('commune', fn (Builder $commune) => $commune->where('province_code', $user->province_code)),
            'commune' => $query->where('commune_code', $user->commune_code),
            'village' => $query->whereIn('code', $user->managedVillageCodes()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function assertCanUseCommune(User $user, string $communeCode, ?Village $existingVillage): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        $commune = Commune::where('code', $communeCode)->firstOrFail();
        $allowed = match ($user->roleScopeLevel()) {
            'province' => $commune->province_code === $user->province_code,
            'commune' => $communeCode === $user->commune_code,
            'village' => $existingVillage !== null
                && in_array($existingVillage->code, $user->managedVillageCodes(), true)
                && $communeCode === $existingVillage->commune_code
                && $communeCode === $user->commune_code,
            default => false,
        };

        abort_unless($allowed, 403);
    }
}
