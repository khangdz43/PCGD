<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermission;
use App\Support\DelegatedAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function getCommunes(string $provinceCode, Request $request)
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

        $communeList = $communes->select('code', 'name')->get();
        $user = $request->user();
        if ($request->boolean('include_account_counts')
            && $user->hasRole(Role::SYSTEM_ADMIN)
            && $user->can(AccessPermission::VIEW_ORGANIZATION_ACCOUNTS)) {
            $counts = DB::table('users')
                ->whereIn('commune_code', $communeList->pluck('code'))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->whereColumn('model_has_roles.model_id', 'users.id')
                        ->where('model_has_roles.model_type', User::class)
                        ->where('roles.name', Role::SYSTEM_ADMIN);
                })
                ->select('commune_code')
                ->selectRaw('COUNT(*) AS account_count')
                ->groupBy('commune_code')
                ->pluck('account_count', 'commune_code');

            $communeList->each(fn ($commune) => $commune->account_count = $counts[$commune->code] ?? 0);
        }

        return response()->json($communeList);
    }
}
