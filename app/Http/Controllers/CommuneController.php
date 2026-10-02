<?php

namespace App\Http\Controllers;

use App\Models\Commune;
use Illuminate\Http\Request;

class CommuneController extends Controller
{
    // lấy thôn theo xã
    public function getVillages(string $communeCode)
    {

        $commune = Commune::where('code', $communeCode)->firstOrFail();

        return response()->json($commune->villages()->select('code', 'name')->get());
    }
}
