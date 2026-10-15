<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Village;
use App\Services\Geography\VillageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VillageController extends Controller
{
    public function __construct(private readonly VillageService $villages) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $villages = $this->villages->listing($user, $request->only(['q', 'province_code', 'commune_code']));
        $provinces = $this->villages->provincesFor($user);
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
        $provinces = $this->villages->provincesFor($request->user());
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
        $this->villages->create($request->user(), $data);

        return redirect()->to(route('villages.index', [], false))
            ->with('success', 'Thêm mới thôn/bản thành công!');
    }

    public function edit(string $code, Request $request)
    {
        $village = $this->villages->findWithCommune($code);
        $this->villages->assertCanUseCommune($request->user(), $village->commune_code, $village);
        $provinceCode = $village->commune->province_code;
        $provinces = $this->villages->provincesFor($request->user());

        return view('villages.edit', compact('village', 'provinces', 'provinceCode'));
    }

    public function update(Request $request, string $code)
    {
        $village = $this->villages->findWithCommune($code);
        $this->villages->assertCanUseCommune($request->user(), $village->commune_code, $village);

        $data = $request->validate(array_merge([
            'name' => 'required|string|max:255',
        ], $this->locationRules($request)));
        $this->villages->update($request->user(), $village, $data);

        return redirect()->to(route('villages.index', [], false))
            ->with('success', 'Cập nhật thông tin thôn/bản thành công!');
    }

    public function destroy(Request $request, string $code)
    {
        $village = $this->villages->findWithCommune($code);
        $this->villages->delete($request->user(), $village);

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
}
