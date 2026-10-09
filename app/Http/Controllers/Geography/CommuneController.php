<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Models\Commune;
use App\Models\Role;

class CommuneController extends Controller
{
    // lấy thôn theo xã
    public function getVillages(string $communeCode)
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

        return response()->json($villages->select('code', 'name')->get());
    }
}
