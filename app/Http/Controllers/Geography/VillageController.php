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
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'province_code' => ['nullable', 'string', 'max:50'],
            'commune_code' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $villages = $this->villages->listing($user, $filters);
        $provinces = $this->villages->provincesFor($user);
        $provinceCode = $user->hasRole(Role::SYSTEM_ADMIN) ? '' : $user->province_code;
        $communeCode = in_array($user->roleScopeLevel(), ['commune', 'village'], true)
            ? $user->commune_code
            : '';

        return response()->json([
            'villages' => $villages,
            'provinces' => $provinces,
            'province_code' => $provinceCode,
            'commune_code' => $communeCode,
        ]);
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

        return response()->json([
            'village' => $village,
            'provinces' => $provinces,
            'province_code' => $provinceCode,
            'commune_code' => $communeCode,
        ]);
    }

    public function show(string $code, Request $request)
    {
        $village = $this->villages->findWithCommune($code);
        $this->villages->assertCanUseCommune($request->user(), $village->commune_code, $village);

        return response()->json(['data' => $village]);
    }

    public function store(Request $request)
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(array_merge([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('villages', 'name')->where('commune_code', $request->input('commune_code')),
            ],
        ], $this->locationRules($request)), [
            'name.unique' => 'Tên thôn/xóm đã tồn tại trong xã này.',
        ]);
        $this->villages->create($request->user(), $data);

        return response()->json(['message' => 'Thêm mới thôn/bản thành công!'], 201);
    }

    public function edit(string $code, Request $request)
    {
        $village = $this->villages->findWithCommune($code);
        $this->villages->assertCanUseCommune($request->user(), $village->commune_code, $village);
        $provinceCode = $village->commune->province_code;
        $provinces = $this->villages->provincesFor($request->user());

        return response()->json([
            'village' => $village,
            'provinces' => $provinces,
            'province_code' => $provinceCode,
        ]);
    }

    public function update(Request $request, string $code)
    {
        $village = $this->villages->findWithCommune($code);
        $this->villages->assertCanUseCommune($request->user(), $village->commune_code, $village);

        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(array_merge([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('villages', 'name')
                    ->where('commune_code', $request->input('commune_code'))
                    ->ignore($village->code, 'code'),
            ],
        ], $this->locationRules($request)), [
            'name.unique' => 'Tên thôn/xóm đã tồn tại trong xã này.',
        ]);
        $this->villages->update($request->user(), $village, $data);

        return response()->json(['message' => 'Cập nhật thông tin thôn/bản thành công!']);
    }

    public function destroy(Request $request, string $code)
    {
        $village = $this->villages->findWithCommune($code);
        $this->villages->delete($request->user(), $village);

        return response()->json(['message' => 'Đã xóa thôn/bản thành công!']);
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
