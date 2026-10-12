<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Models\Commune;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommuneController extends Controller
{
    // lấy thôn theo xã
    public function getVillages(string $communeCode, Request $request)
    {

        $commune = Commune::where('code', $communeCode)->firstOrFail();
        $villages = $commune->villages();
        $user = request()->user();

        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            if ($user->province_code !== $commune->province_code) {
                $villages->whereRaw('1 = 0');
            }

            if (in_array($user->roleScopeLevel(), ['commune', 'village'], true)
                && $user->commune_code !== $communeCode) {
                $villages->whereRaw('1 = 0');
            }

            if ($user->roleScopeLevel() === 'village') {
                $villages->whereIn('code', $user->managedVillageCodes());
            }
        }

        $villageList = $villages->select('code', 'name')->get();
        if ($request->boolean('include_account_counts')
            && $user->hasRole(Role::SYSTEM_ADMIN)
            && $user->can(AccessPermission::VIEW_ORGANIZATION_ACCOUNTS)) {
            $counts = DB::table('user_villages')
                ->join('users', 'users.id', '=', 'user_villages.user_id')
                ->whereIn('user_villages.village_code', $villageList->pluck('code'))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->whereColumn('model_has_roles.model_id', 'users.id')
                        ->where('model_has_roles.model_type', User::class)
                        ->where('roles.name', Role::SYSTEM_ADMIN);
                })
                ->select('user_villages.village_code')
                ->selectRaw('COUNT(*) AS account_count')
                ->groupBy('user_villages.village_code')
                ->pluck('account_count', 'village_code');

            $villageList->each(fn ($village) => $village->account_count = $counts[$village->code] ?? 0);
        }

        return response()->json($villageList);
    }
}
