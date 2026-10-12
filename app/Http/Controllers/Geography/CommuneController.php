<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Services\Geography\GeographyLookupService;
use Illuminate\Http\Request;

class CommuneController extends Controller
{
    public function __construct(private readonly GeographyLookupService $geography) {}

    public function getVillages(string $communeCode, Request $request)
    {
        $villages = $this->geography->villagesForCommune(
            $communeCode,
            $request->user(),
            $request->boolean('include_account_counts')
        );

        return response()->json($villages);
    }
}
