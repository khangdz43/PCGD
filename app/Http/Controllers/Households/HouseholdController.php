<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Support\DataAccessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HouseholdController extends Controller
{
    // view xem chủ hộ
    public function index(Request $request)
    {
        $user = $request->user();
        $defaultFilters = ['school_year' => $this->currentSchoolYear()];
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            if ($user->province_code !== null) {
                $defaultFilters['province_code'] = $user->province_code;
            }

            if (in_array($user->roleScopeLevel(), ['commune', 'village'], true) && $user->commune_code !== null) {
                $defaultFilters['commune_code'] = $user->commune_code;
            }

            $managedVillageCodes = $user->roleScopeLevel() === 'village'
                ? $user->managedVillageCodes()
                : [];
            if (count($managedVillageCodes) === 1) {
                $defaultFilters['village_code'] = $managedVillageCodes[0];
            }
        }

        foreach ($defaultFilters as $filter => $value) {
            if (! $request->filled($filter)) {
                $request->merge([$filter => $value]);
            }
        }

        $query = Household::with([
            'province',
            'commune',
            'village',
            'persons',
            'persons.ethnicity',
            'persons.educations',
            'persons.educations.school',
            'persons.disability',
        ])->withCount([
            'persons',
            'persons as head_persons_count' => fn ($builder) => $builder->where('relationship_with_head', 'Chủ hộ'),
        ]);

        DataAccessScope::households($query, $user);

        if ($request->filled('q')) {
            $search = $request->string('q')->trim()->toString();
            $query->where(function ($builder) use ($search): void {
                $builder->where('household_code', 'like', "%{$search}%");
                $builder->orWhere('head_first_name', 'like', "%{$search}%")
                    ->orWhere('head_last_name', 'like', "%{$search}%")
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

        if ($request->filled('school_year') && $request->input('school_year') !== 'all') {
            $query->where('school_year', $request->input('school_year'));
        }

        if ($request->filled('school_code')) {
            $query->whereHas('persons.educations', function ($builder) use ($request) {
                $builder->where('school_code', $request->input('school_code'));
            });
        }

        $households = $query->orderBy('household_code')->paginate(15)->withQueryString();
        $provincesQuery = Province::orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $provincesQuery->where('code', $user->province_code);
        }
        $provinces = $provincesQuery->get(['code', 'name']);
        $managedVillageCodes = $user->roleScopeLevel() === 'village'
            ? $user->managedVillageCodes()
            : [];
        $villagePlaceholder = count($managedVillageCodes) > 1
            ? '-- Tất cả thôn được giao --'
            : '-- Chọn Thôn/Bản --';

        return view('households.index', compact('households', 'provinces', 'villagePlaceholder'));
    }

    public function create(Request $request)
    {
        $household = new Household;
        $user = $request->user();
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $household->province_code = $user->province_code;
            $household->commune_code = $user->commune_code;
            $villageCodes = $user->managedVillageCodes();
            if ($user->roleScopeLevel() === 'village' && count($villageCodes) === 1) {
                $household->village_code = $villageCodes[0];
            }
        }
        $household->household_code = $this->nextHouseholdCode();
        $household->school_year = $this->currentSchoolYear();
        $provincesQuery = Province::orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $provincesQuery->where('code', $user->province_code);
        }
        $provinces = $provincesQuery->get(['code', 'name']);

        return view('households.create', compact('household', 'provinces'));
    }

    public function store(Request $request)
    {
        $rules = $this->rules($request);
        unset($rules['household_code']);

        $data = $request->validate($rules);
        DataAccessScope::assertHouseholdLocation($request->user(), $data);
        $data['household_code'] = $this->nextHouseholdCode($data['school_year']);
        $household = Household::create($data);

        return redirect()->to(route('households.members.create', [$household, 'is_head' => 1], false))
            ->with('status', 'Đã lưu thông tin hộ. Tiếp theo, nhập thông tin chủ hộ vào danh sách nhân khẩu.');
    }

    public function show(Household $household)
    {
        $this->assertAccess($household);
        $household->load('province', 'commune', 'village');

        $personsQuery = $household->persons()->with('ethnicity');
        $persons = $personsQuery->orderBy('last_name')->orderBy('first_name')->get();
        $headPerson = $persons->firstWhere('relationship_with_head', 'Chủ hộ');
        $hasAnyHouseholdHead = $household->persons()->where('relationship_with_head', 'Chủ hộ')->exists();
        $canSeeHouseholdHead = true;

        return view('households.show', compact('household', 'headPerson', 'persons', 'canSeeHouseholdHead', 'hasAnyHouseholdHead'));
    }

    public function edit(Household $household)
    {
        $this->assertAccess($household);
        /** @var User $user */
        $user = request()->user();
        $provincesQuery = Province::orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $provincesQuery->where('code', $user->province_code);
        }
        $provinces = $provincesQuery->get(['code', 'name']);

        return view('households.edit', compact('household', 'provinces'));
    }

    public function update(Request $request, Household $household)
    {
        $this->assertAccess($household);
        $data = $request->validate($this->rules($request, $household));
        DataAccessScope::assertHouseholdLocation($request->user(), $data);
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
        $this->assertAccess($household);
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

        $accessibleHouseholds = DataAccessScope::households(Household::query(), $request->user())
            ->whereIn('id', $data['household_ids'])
            ->get();
        abort_unless($accessibleHouseholds->count() === count($data['household_ids']), 404);
        $deletedCount = Household::whereIn('id', $accessibleHouseholds->modelKeys())->delete();
        // theo cả điều kiện xóa
        $filters = $request->only(['q', 'school_code', 'school_year', 'province_code', 'commune_code', 'village_code', 'page']);

        return redirect()->to(route('households.index', $filters, false))
            ->with('success', "Đã xóa {$deletedCount} phiếu điều tra.");
    }

    private function rules(Request $request, ?Household $household = null): array
    {
        $householdCodeRule = Rule::unique('households', 'household_code')
            ->where(fn ($query) => $query->where('school_year', $request->input('school_year')));
        if ($household) {
            $householdCodeRule->ignore($household->id);
        }

        $locationRules = [
            'province_code' => ['required', 'exists:provinces,code'],
            'commune_code' => [
                'required',
                Rule::exists('communes', 'code')->where('province_code', $request->input('province_code')),
            ],
            'village_code' => [
                'nullable',
                Rule::exists('villages', 'code')->where('commune_code', $request->input('commune_code')),
            ],
        ];

        $user = $request->user();
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            if ($user->province_code) {
                $locationRules['province_code'][] = Rule::in([$user->province_code]);
            }
            if (in_array($user->roleScopeLevel(), ['commune', 'village'], true) && $user->commune_code) {
                $locationRules['commune_code'][] = Rule::in([$user->commune_code]);
            }
            if ($user->roleScopeLevel() === 'village') {
                $locationRules['village_code'][] = Rule::in($user->managedVillageCodes());
            }
        }

        return [
            'household_code' => ['required', 'string', 'max:50', $householdCodeRule],
            'school_year' => ['required', Rule::in($this->schoolYears())],
            'head_first_name' => 'required|string|max:100',
            'head_last_name' => 'required|string|max:50',
            ...$locationRules,
            'address' => 'nullable|string|max:1000',
            'residence_type' => 'required|in:THUONG_TRU,TAM_TRU,KHAC',
            'residence_status' => 'nullable|string|max:100',
        ];
    }

    private function assertAccess(Household $household): void
    {
        /** @var User $user */
        $user = request()->user();
        abort_unless(DataAccessScope::canAccessHousehold($user, $household), 404);
    }

    private function nextHouseholdCode(?string $schoolYear = null): string
    {
        $query = Household::query();
        if ($schoolYear !== null) {
            $query->where('school_year', $schoolYear);
        }

        $maxCode = $query
            ->pluck('household_code')
            ->filter(fn ($code) => ctype_digit((string) $code))
            ->map(fn ($code) => (int) $code)
            ->max() ?? 0;

        return str_pad((string) ($maxCode + 1), 2, '0', STR_PAD_LEFT);
    }

    private function schoolYears(): array
    {
        // vì năm học thường kết thức sau tháng tám
        $lastSchoolYear = now()->month >= 8 ? now()->year : now()->year - 1;

        return array_map(
            fn (int $year): string => "{$year}-".($year + 1),
            range(2020, $lastSchoolYear)
        );
    }

    private function currentSchoolYear(): string
    {
        $year = now()->year;
        $startYear = now()->month >= 8 ? $year : $year - 1;

        return $startYear.'-'.($startYear + 1);
    }
}
