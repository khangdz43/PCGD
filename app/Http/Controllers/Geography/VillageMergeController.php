<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Services\Geography\VillageService;
use Illuminate\Http\Request;

class VillageMergeController extends Controller
{
    public function __construct(private readonly VillageService $villages) {}

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user->roleScopeLevel() === 'commune' && filled($user->commune_code), 403);

        $villages = $this->villages->availableForMerge($user);

        return view('utilities.merge-villages', compact('villages'));
    }
}
