<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Role;
use App\Models\User;
use App\Services\Households\HouseholdService;
use App\Support\DataAccessScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HouseholdController extends Controller
{
    public function __construct(private readonly HouseholdService $households) {}

    // view xem chủ hộ
    public function index(Request $request)
    {
        $user = $request->user();
        $defaultFilters = $this->households->defaultFilters($user);
        foreach ($defaultFilters as $filter => $value) {
            if (! $request->filled($filter)) {
                $request->merge([$filter => $value]);
            }
        }

        $households = $this->households->paginate($user, $request->only([
            'q',
            'province_code',
            'commune_code',
            'village_code',
            'school_year',
            'school_code',
        ]));
        $provinces = $this->households->provincesFor($user);
        $villagePlaceholder = $this->households->villagePlaceholder($user);

        return view('households.index', compact('households', 'provinces', 'villagePlaceholder'));
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $household = $this->households->newFor($user);
        $provinces = $this->households->provincesFor($user);

        return view('households.create', compact('household', 'provinces'));
    }

    public function store(Request $request)
    {
        $rules = $this->rules($request);
        unset($rules['household_code']);

        $data = $request->validate($rules);
        $household = $this->households->create($request->user(), $data);

        return redirect()->to(route('households.members.create', [$household, 'is_head' => 1], false))
            ->with('status', 'Đã lưu thông tin hộ. Tiếp theo, nhập thông tin chủ hộ vào danh sách nhân khẩu.');
    }

    public function show(Household $household)
    {
        $this->assertAccess($household);
        ['persons' => $persons, 'headPerson' => $headPerson, 'hasAnyHouseholdHead' => $hasAnyHouseholdHead] =
            $this->households->details($household);
        $canSeeHouseholdHead = true;

        return view('households.show', compact('household', 'headPerson', 'persons', 'canSeeHouseholdHead', 'hasAnyHouseholdHead'));
    }

    public function edit(Household $household)
    {
        $this->assertAccess($household);
        /** @var User $user */
        $user = request()->user();
        $provinces = $this->households->provincesFor($user);

        return view('households.edit', compact('household', 'provinces'));
    }

    public function update(Request $request, Household $household)
    {
        $this->assertAccess($household);
        $data = $request->validate($this->rules($request, $household));
        $this->households->update($household, $request->user(), $data);

        return redirect()->to(route('households.show', $household, false))
            ->with('success', 'Đã cập nhật phiếu điều tra.');
    }

    public function destroy(Household $household)
    {
        $this->assertAccess($household);
        $this->households->delete($household);

        return redirect()->to(route('households.index', [], false))->with('success', 'Đã xóa phiếu điều tra.');
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'household_ids' => ['required', 'array', 'min:1'],
            // mọi phần tử trong mảng id
            'household_ids.*' => ['integer', 'distinct', 'exists:households,id'],
        ]);

        $deletedCount = $this->households->deleteMany($request->user(), $data['household_ids']);
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
            'school_year' => ['required', Rule::in($this->households->schoolYears())],
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
}
