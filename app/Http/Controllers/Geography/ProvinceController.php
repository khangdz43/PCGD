<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Services\Geography\GeographyLookupService;
use Illuminate\Http\Request;

class ProvinceController extends Controller
{
    public function __construct(private readonly GeographyLookupService $geography) {}

    public function home(Request $request)
    {
        $provinces = $this->geography->provincesForHome($request->user());

        return view('index', compact('provinces'));
    }

    public function getCommunes(string $provinceCode, Request $request)
    {
        $communes = $this->geography->communesForProvince(
            $provinceCode,
            $request->user(),
            $request->string('available_for')->toString(),
            $request->integer('except') ?: null,
            $request->boolean('include_account_counts')
        );

        return response()->json($communes);
    }
}
