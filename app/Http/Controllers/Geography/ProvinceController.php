<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Models\Province;
use App\Models\Role;
use App\Support\DelegatedAccess;

class ProvinceController extends Controller
{
    // lấy toàn bộ danh sách các tỉnh
    public function home()
    {
        $query = Province::select('code', 'name');
        $user = request()->user(); // lấy user đang đăng nhập
        // nếu k phải admin thì chỉ lấy giới hạn của người đó thôi 1 tỉnh
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $query->where('code', $user->province_code);
        }
        $provinces = $query->get();

        return view('index', compact('provinces'));
    }

    // lấy xã của 1 tỉnh
    public function getCommunes(string $provinceCode)
    {
        $province = Province::where('code', $provinceCode)->firstOrFail();
        $communes = $province->communes();
        $user = request()->user();

        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            // ko cho truy cập tỉnh khác  1= 0 false nên nó sẽ ko lọt
            if ($user->province_code !== $provinceCode) {
                $communes->whereRaw('1 = 0');
            }

            // check xem nhóm scope quyền (true ===) lấy đúng cái xã đấy
            if (in_array($user->roleScopeLevel(), ['commune', 'village'], true)) {
                $communes->where('code', $user->commune_code);
            }
        }

        if (request()->string('available_for')->toString() === 'commune-admin') {
            $exceptUserId = request()->integer('except') ?: null;
            $occupied = DelegatedAccess::occupiedTerritorialCodes('commune', $exceptUserId);
            if ($occupied !== []) {
                $communes->whereNotIn('code', $occupied);
            }
        }

        return response()->json($communes->select('code', 'name')->get());
    }
}
