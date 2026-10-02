<?php

namespace App\Http\Controllers;

use App\Models\Province;
use Illuminate\Http\Request;

class ProvinceController extends Controller
{
    // lấy toàn bộ danh sách các tỉnh
    public function home()
    {
        $provinces = Province::select('code', 'name')->get();
        return view('index', compact('provinces'));
    }


    // lấy xã của 1 tỉnh
    public function getCommunes(string $provinceCode)
    {
        $province = Province::where('code', $provinceCode)->firstOrFail();
        return response()->json($province->communes()->select('code', 'name')->get());
    }
}
