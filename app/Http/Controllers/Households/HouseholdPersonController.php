<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Person;
use App\Models\PersonEducation;
use App\Models\Role;
use App\Services\Households\HouseholdPersonService;
use App\Support\DataAccessScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HouseholdPersonController extends Controller
{
    public function __construct(private readonly HouseholdPersonService $people) {}

    public function create(Request $request, Household $household)
    {
        $this->assertHouseholdAccess($request, $household);
        // is_head 1 thì là true
        $isHouseholdHead = $request->boolean('is_head');
        $headPerson = $this->people->headFor($household);
        $headPersonExists = $headPerson !== null;

        // excep
        if ($isHouseholdHead && $headPersonExists) {
            return response()->json(['message' => 'Hộ này đã có hồ sơ chủ hộ.'], 422);
        }

        if (! $isHouseholdHead && ! $headPersonExists) {
            return response()->json(['message' => 'Cần nhập hồ sơ chủ hộ trước khi thêm thành viên khác.'], 422);
        }

        $person = $this->people->newPerson($household, $isHouseholdHead, $headPerson);
        // mặc định tên của chủ hộ khi thêm vào bảng person
        $formData = $this->people->formData($person, $isHouseholdHead, $request->user(), $household->school_year);

        // lúc thêm thành viên mới có các thông tin của chủ hộ , person (Nam) , formdataa (năm)
        return response()->json([
            'household' => $household,
            'person' => $person,
            'is_household_head' => $isHouseholdHead,
            ...$formData,
        ]);
    }

    public function store(Request $request, Household $household)
    {
        $this->assertHouseholdAccess($request, $household);
        $isHouseholdHead = $request->boolean('is_head');
        $headPerson = $this->people->headFor($household);
        $headPersonExists = $headPerson !== null;

        // xu li excep
        if (! $isHouseholdHead && ! $headPersonExists) {
            return response()->json(['message' => 'Cần nhập hồ sơ chủ hộ trước khi thêm thành viên khác.'], 422);
        }

        if ($isHouseholdHead && $headPersonExists) {
            return response()->json(['message' => 'Hộ này đã có hồ sơ chủ hộ.'], 422);
        }

        $data = $request->validate($this->rules($request, $isHouseholdHead));
        $relationshipAgeError = $this->people->relationshipAgeError($data['relationship_with_head'] ?? null, $data['dob'], $headPerson);
        if ($relationshipAgeError !== null) {
            return response()->json([
                'message' => $relationshipAgeError,
                'errors' => ['relationship_with_head' => [$relationshipAgeError]],
            ], 422);
        }

        $this->people->create($household, $data, $isHouseholdHead);

        return response()->json([
            'message' => $isHouseholdHead
                ? 'Đã thêm chủ hộ vào danh sách nhân khẩu. Bây giờ có thể thêm các thành viên khác.'
                : 'Đã thêm thành viên vào hộ.',
        ], 201);
    }

    public function show(Household $household, int $personId)
    {
        $this->assertHouseholdAccess(request(), $household);
        $person = $this->people->findWithDetails($household, $personId);

        return response()->json(['household' => $household, 'person' => $person]);
    }

    public function edit(Household $household, int $personId)
    {
        $this->assertHouseholdAccess(request(), $household);
        $person = $this->people->find($household, $personId);
        $isHouseholdHead = $person->relationship_with_head === 'Chủ hộ';
        $formData = $this->people->formData($person, $isHouseholdHead, request()->user(), $household->school_year);

        return response()->json([
            'household' => $household,
            'person' => $person,
            'is_household_head' => $isHouseholdHead,
            ...$formData,
        ]);
    }

    public function update(Request $request, Household $household, int $personId)
    {
        $this->assertHouseholdAccess($request, $household);
        // người trong hộ này
        $person = $this->people->find($household, $personId);
        $isHouseholdHead = $person->relationship_with_head === 'Chủ hộ';
        $headPerson = $isHouseholdHead ? null : $this->people->headFor($household);
        // tìm năm học mới nhất vì trong bảng education sẽ có nhiều năm (varchar )
        $education = $this->people->latestEducation($person);
        // validate
        $data = $request->validate($this->rules($request, $isHouseholdHead, $person, $education));
        $relationshipAgeError = $this->people->relationshipAgeError($data['relationship_with_head'] ?? null, $data['dob'], $headPerson);
        if ($relationshipAgeError !== null) {
            return response()->json([
                'message' => $relationshipAgeError,
                'errors' => ['relationship_with_head' => [$relationshipAgeError]],
            ], 422);
        }

        $this->people->update($household, $person, $data, $isHouseholdHead, $education);

        return response()->json(['message' => 'Đã cập nhật thành viên.']);
    }

    public function destroy(Request $request, Household $household, int $personId)
    {
        $this->assertHouseholdAccess($request, $household);
        $person = $this->people->find($household, $personId);
        $isHouseholdHead = $this->people->delete($person, $household, $request->user());
        if ($isHouseholdHead === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Hãy xóa các thành viên khác trước khi xóa hồ sơ chủ hộ.',
                ], 422);
            }

            return response()->json([
                'message' => 'Hãy xóa các thành viên khác trước khi xóa hồ sơ chủ hộ.',
            ], 422);
        }

        $message = $isHouseholdHead
            ? 'Đã xóa hồ sơ chủ hộ. Cần nhập lại chủ hộ trước khi thêm thành viên.'
            : 'Đã xóa thành viên khỏi hộ.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'is_household_head' => $isHouseholdHead,
                'remaining_count' => $household->persons()->count(),
            ]);
        }

        return response()->json(['message' => $message]);
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'person_ids' => ['required', 'array', 'min:1'],
            'person_ids.*' => ['integer', 'distinct', 'exists:persons,id'],
        ]);
        // lấy các id person mà client tick trong list ids
        $persons = $this->people->findManyForDeletion($request->user(), $data['person_ids']);
        if ($this->people->hasNonHeadMembersForSelectedHeads($persons)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Không thể xóa chủ hộ khi hộ vẫn còn thành viên. Hãy xóa các thành viên khác trước.',
                ], 422);
            }

            return response()->json([
                'message' => 'Không thể xóa chủ hộ khi hộ vẫn còn thành viên. Hãy xóa các thành viên khác trước.',
            ], 422);
        }
        // xóa các thằng hợp lệ
        $this->people->deleteMany($persons, $request->user());

        $message = "Đã xóa {$persons->count()} thành viên đã chọn.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'deleted_ids' => $persons->modelKeys(),
            ]);
        }

        return response()->json([
            'message' => $message,
            'deleted_ids' => $persons->modelKeys(),
        ]);
    }

    private function rules(Request $request, bool $isHouseholdHead = false, ?Person $person = null, ?PersonEducation $education = null): array
    {
        $user = $request->user();
        $rules = [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:50',
            'dob' => 'required|date|before_or_equal:today',
            'gender' => 'required|in:NAM,NU',
            'ethnicity_code' => 'required|exists:ethnicities,code',
            'religion' => 'nullable|string|max:50',
            'priority_type' => 'nullable|string|max:100',
            'parent_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:20',
            'note' => 'nullable|string|max:2000',
        ];

        $rules['relationship_with_head'] = $isHouseholdHead
            ? 'nullable|string|max:100'
            : ['required', 'string', 'max:100', Rule::notIn(['Chủ hộ'])];

        // chỉ cho phép những cái có
        $schoolYearRules = ['required', Rule::in($this->people->schoolYears())];
        if ($person?->exists) {
            // 1 person chỉ được có 1 education trong 1 năm
            $uniqueSchoolYear = Rule::unique('person_educations', 'school_year')->where('person_id', $person->id);
            if ($education?->exists) {
                // cái trên chỉ chấp nhận unique mà người dùng k sửa thì sẽ k sao
                $uniqueSchoolYear->ignore($education->id);
            }
            // thêm đối tượng rule vào mảng
            $schoolYearRules[] = $uniqueSchoolYear;
        }

        $notFutureYear = function ($attribute, $value, $fail) {
            preg_match_all('/(?<!\d)\d{4}(?!\d)/', (string) $value, $matches);
            foreach ($matches[0] as $year) {
                if ((int) $year > now()->year) {
                    $fail('Năm không được lớn hơn '.now()->year.'.');

                    return;
                }
            }
        };

        $schoolProvinceRules = ['nullable', 'exists:provinces,code'];
        $schoolCommuneRules = ['nullable', Rule::exists('communes', 'code')
            ->where('province_code', $request->input('school_province_code'))];
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            if ($user->province_code !== null) {
                $schoolProvinceRules[] = Rule::in([$user->province_code]);
            }

            if (in_array($user->roleScopeLevel(), ['commune', 'village'], true)) {
                $schoolCommuneRules[] = Rule::in([$user->commune_code]);
            }
        }

        $schoolExistsRule = Rule::exists('schools', 'code')
            ->where('province_code', $request->input('school_province_code'))
            ->where('commune_code', $request->input('school_commune_code'));
        if (! $user->hasRole(Role::SYSTEM_ADMIN)
            && in_array($user->roleScopeLevel(), ['commune', 'village'], true)) {
            $schoolExistsRule
                ->where('province_code', $user->province_code)
                ->where('commune_code', $user->commune_code);
        }

        $rules += [
            'school_province_code' => $schoolProvinceRules,
            'school_commune_code' => $schoolCommuneRules,
            'education.school_year' => $schoolYearRules,
            'education.academic_block' => 'nullable|string|max:50',
            'education.current_class' => 'nullable|string|max:50',
            'education.school_code' => [
                'nullable',
                $schoolExistsRule,
            ],
            'education.graduation_level' => ['nullable', Rule::in(['MN', 'TH', 'THCS', 'THPT'])],
            'education.is_complementary' => 'nullable|boolean',
            'education.graduation_year' => ['nullable', 'string', 'max:20', $notFutureYear],
            'education.vocational_grad_level' => 'nullable|string|max:50',
            'education.vocational_grad_year' => ['nullable', 'string', 'max:20', $notFutureYear],
            'education.finished_class' => 'nullable|string|max:20',
            'education.finished_year' => ['nullable', 'string', 'max:20', $notFutureYear],
            'education.dropped_class' => 'nullable|string|max:20',
            'education.dropped_year' => ['nullable', 'string', 'max:20', $notFutureYear],
            'education.literacy_current_class' => 'nullable|string|max:20',
            'education.literacy_completed_class' => 'nullable|string|max:20',
            'education.literacy_relapse_level' => 'nullable|integer|between:1,2',
            'education.learning_capacity' => 'nullable|string|max:50',
        ];

        foreach ($this->people->disabilityBooleanFields() as $field) {
            // toàn bộ fieild ở disbility lấy ở method trên cho null và boolean
            $rules["disability.{$field}"] = 'nullable|boolean';
        }

        $rules += [
            'disability.special_circumstance' => ['nullable', Rule::in(['Chuyển đến', 'Chuyển đi'])],
            'disability.special_circumstance_detail' => 'nullable|string|max:2000',
        ];

        return $rules;
    }

    private function assertHouseholdAccess(Request $request, Household $household): void
    {
        abort_unless(DataAccessScope::canAccessHousehold($request->user(), $household), 404);
    }
}
