<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Province;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HouseholdController extends Controller
{
    // view xem chủ hộ
    public function index(Request $request)
    {
        // lấy household lấy cả các bảng kia
        $query = Household::with([
            'province',
            'commune',
            'village',
            'persons.ethnicity',
            'persons.educations.school',
            'persons.disability',
        ])->withCount([
            'persons',
            // đếm số người trong 1 hộ
            // lấy ra chủ hộ
            'persons as head_persons_count' => fn($builder) => $builder->where('relationship_with_head', 'Chủ hộ'),
        ]);

        if ($request->filled('q')) {
            $search = $request->string('q')->trim()->toString();
            $query->where(function ($builder) use ($search) {
                $builder->where('household_code', 'like', "%{$search}%")
                    ->orWhere('head_first_name', 'like', "%{$search}%")
                    ->orWhere('head_last_name', 'like', "%{$search}%")
                    // search cả  2 match vào 
                    ->orWhereRaw("CONCAT(head_last_name, ' ', head_first_name) LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("CONCAT(head_first_name, ' ', head_last_name) LIKE ?", ["%{$search}%"]);
            });
        }
        // hiển thị cả số lượng kết quả trả ra 



        foreach (['province_code', 'commune_code', 'village_code'] as $locationField) {
            if ($request->filled($locationField)) {
                $query->where($locationField, $request->input($locationField));
            }
        }

        if ($request->filled('school_year')) {
            $query->where('school_year', $request->input('school_year'));
        }

        if ($request->filled('school_code')) {
            $query->whereHas('persons.educations', function ($builder) use ($request) {
                $builder->where('school_code', $request->input('school_code'));
            });
        }

        $households = $query->orderBy('household_code')->paginate(15)->withQueryString();
        $provinces = Province::orderBy('name')->get(['code', 'name']);

        return view('households.index', compact('households', 'provinces'));
    }

    public function create()
    {
        $household = new Household();
        $household->household_code = $this->nextHouseholdCode();
        $household->school_year = $this->currentSchoolYear();
        $provinces = Province::orderBy('name')->get(['code', 'name']);

        return view('households.create', compact('household', 'provinces'));
    }

    public function store(Request $request)
    {
        $rules = $this->rules($request);
        unset($rules['household_code']);

        $data = $request->validate($rules);
        $data['household_code'] = $this->nextHouseholdCode($data['school_year']);
        $household = Household::create($data);

        return redirect()->to(route('households.members.create', [$household, 'is_head' => 1], false))
            ->with('status', 'Đã lưu thông tin hộ. Tiếp theo, nhập thông tin chủ hộ vào danh sách nhân khẩu.');
    }

    public function show(Household $household)
    {
        $household->load('province', 'commune', 'village');
        $headPerson = $household->persons()->where('relationship_with_head', 'Chủ hộ')->first();
        $persons = $household->persons()->with('ethnicity')->orderBy('last_name')->orderBy('first_name')->get();

        return view('households.show', compact('household', 'headPerson', 'persons'));
    }

    public function edit(Household $household)
    {
        $provinces = Province::orderBy('name')->get(['code', 'name']);

        return view('households.edit', compact('household', 'provinces'));
    }

    public function update(Request $request, Household $household)
    {
        $data = $request->validate($this->rules($request, $household));
        $data['school_year'] = $household->school_year;

        DB::transaction(function () use ($household, $data) {
            $household->update($data);
            $household->persons()->where('relationship_with_head', 'Chủ hộ')->update([
                'first_name' => $data['head_first_name'],
                'last_name' => $data['head_last_name'],
            ]);
        });

        return redirect()->to(route('households.show', $household, false))
            ->with('success', 'Đã cập nhật phiếu điều tra.');
    }

    public function destroy(Household $household)
    {
        $household->delete();

        return redirect()->to(route('households.index', [], false))->with('success', 'Đã xóa phiếu điều tra.');
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'household_ids' => ['required', 'array', 'min:1'],
            // mọi phần tử trong mảng id
            'household_ids.*' => ['integer', 'distinct', 'exists:households,id'],
        ]);

        $deletedCount = Household::whereIn('id', $data['household_ids'])->delete();
        // theo cả điều kiện xóa
        $filters = $request->only(['q', 'school_code', 'school_year', 'province_code', 'commune_code', 'village_code', 'page']);


        return redirect()->to(route('households.index', $filters, false))
            ->with('success', "Đã xóa {$deletedCount} phiếu điều tra.");
    }

    private function rules(Request $request, ?Household $household = null): array
    {
        $householdCodeRule = Rule::unique('households', 'household_code')
            ->where(fn($query) => $query->where('school_year', $request->input('school_year')));
        if ($household) {
            $householdCodeRule->ignore($household->id);
        }

        return [
            'household_code' => ['required', 'string', 'max:50', $householdCodeRule],
            'school_year' => ['required', Rule::in($this->schoolYears())],
            'head_first_name' => 'required|string|max:100',
            'head_last_name' => 'required|string|max:50',
            'province_code' => 'required|exists:provinces,code',
            'commune_code' => [
                'required',
                Rule::exists('communes', 'code')->where('province_code', $request->input('province_code')),
            ],
            'village_code' => [
                'nullable',
                Rule::exists('villages', 'code')->where('commune_code', $request->input('commune_code')),
            ],
            'address' => 'nullable|string|max:1000',
            'residence_type' => 'required|in:THUONG_TRU,TAM_TRU,KHAC',
            'residence_status' => 'nullable|string|max:100',
        ];
    }

    private function nextHouseholdCode(?string $schoolYear = null): string
    {
        $query = Household::query();
        if ($schoolYear !== null) {
            $query->where('school_year', $schoolYear);
        }

        $maxCode = $query
            ->pluck('household_code')
            ->filter(fn($code) => ctype_digit((string) $code))
            ->map(fn($code) => (int) $code)
            ->max() ?? 0;

        return str_pad((string) ($maxCode + 1), 2, '0', STR_PAD_LEFT);
    }

    private function schoolYears(): array
    {
        // vì năm học thường kết thức sau tháng tám
        $lastSchoolYear = now()->month >= 8 ? now()->year : now()->year - 1;

        return array_map(
            fn(int $year): string => "{$year}-" . ($year + 1),
            range(2020, $lastSchoolYear)
        );
    }

    private function currentSchoolYear(): string
    {
        $year = now()->year;
        $startYear = now()->month >= 8 ? $year : $year - 1;

        return $startYear . '-' . ($startYear + 1);
    }
}
