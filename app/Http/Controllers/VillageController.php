<?php

namespace App\Http\Controllers;

use App\Models\Village;
use App\Models\Province;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VillageController extends Controller
{
    public function index(Request $request)
    {
        // tìm luôn xã , từ xã tìm tỉnh tránh n+1 query
        $query = Village::with('commune.province');

        // check para url
        if ($request->filled('q')) {
            $search = $request->string('q')->trim()->toString();
            $query->where(function ($builder) use ($search) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('province_code')) {
            $query->whereHas('commune', fn($builder) => $builder->where('province_code', $request->input('province_code')));
        }

        if ($request->filled('commune_code')) {
            $query->where('commune_code', $request->input('commune_code'));
        }

        $villages = $query->orderBy('name')->paginate(15)->withQueryString();
        $provinces = Province::orderBy('name')->get(['code', 'name']);

        return view('villages.index', compact('villages', 'provinces'));
    }



    // render view
    public function create()
    {
        $village = new Village();
        $provinces = Province::orderBy('name')->get(['code', 'name']);

        return view('villages.create', compact('village', 'provinces'));
    }
    // chuyển hướng 
    public function store(Request $request)
    {
        $data = $request->validate(array_merge([
            'code' => 'required|string|max:20|unique:villages,code',
            'name' => 'required|string|max:255',
        ], $this->locationRules($request)));

        unset($data['province_code']);

        Village::create($data);

        return redirect()->to(route('villages.index', [], false))
            // session success  
            ->with('success', 'Thêm mới thôn/bản thành công!');
    }

    // render view
    public function edit(string $code)
    {
        $village = Village::with('commune')->where('code', $code)->firstOrFail();
        $provinceCode = $village->commune->province_code;
        $provinces = Province::orderBy('name')->get(['code', 'name']);

        return view('villages.edit', compact('village', 'provinces', 'provinceCode'));
    }

    // redirect
    public function update(Request $request, string $code)
    {
        $village = Village::where('code', $code)->firstOrFail();

        $data = $request->validate(array_merge([
            'name' => 'required|string|max:255',
        ], $this->locationRules($request)));

        // xóa khỏi mảng vì lưu xã là cũng biết được tỉnh rồi
        unset($data['province_code']);

        $village->update($data);

        return redirect()->to(route('villages.index', [], false))
            ->with('success', 'Cập nhật thông tin thôn/bản thành công!');
    }

    // redirect
    public function destroy(string $code)
    {
        $village = Village::where('code', $code)->firstOrFail();
        $village->delete();

        return redirect()->to(route('villages.index', [], false))
            ->with('success', 'Đã xóa thôn/bản thành công!');
    }

    private function locationRules(Request $request): array
    {
        return [
            'province_code' => 'required|exists:provinces,code',
            'commune_code' => [
                'required',
                Rule::exists('communes', 'code')->where('province_code', $request->input('province_code')),
            ],
        ];
    }
}
