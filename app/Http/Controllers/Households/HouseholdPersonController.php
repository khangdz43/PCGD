<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Person;
use App\Models\PersonEducation;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Services\Households\HouseholdPersonService;
use App\Support\DataAccessScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HouseholdPersonController extends Controller
{
    public function __construct(private readonly HouseholdPersonService $people) {}

    public function index(Request $request, Household $household)
    {
        $this->assertHouseholdAccess($request, $household);
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $persons = $household->persons()
            ->withDetails()
            ->orderByRaw('CASE WHEN relationship_with_head = ? THEN 0 ELSE 1 END', ['Chủ hộ'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return response()->json($persons);
    }

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

    public function schoolOptions(Request $request, Household $household)
    {
        $this->assertHouseholdAccess($request, $household);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'academic_block' => ['nullable', 'integer', 'between:1,12'],
            'school_level' => ['nullable', Rule::in(['MN'])],
        ]);
        $query = School::query()
            ->where('is_active', true)
            ->where('province_code', $household->province_code);

        if (($filters['school_level'] ?? null) === 'MN') {
            $query->where('level', 'MN');
        } elseif (filled($filters['academic_block'] ?? null)) {
            $grade = (int) $filters['academic_block'];
            $schoolLevels = $this->eligibleSchoolLevelsForGrade($grade);
            $query->where(function ($builder) use ($schoolLevels): void {
                $builder->where('level', $schoolLevels['level']);
                if ($schoolLevels['multi_level'] !== []) {
                    $builder->orWhere(function ($multiLevelQuery) use ($schoolLevels): void {
                        $multiLevelQuery
                            ->where('type', 'multi_level')
                            ->whereIn('level', $schoolLevels['multi_level']);
                    });
                }
            });
        }

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        } elseif (
            ! filled($filters['academic_block'] ?? null)
            && ! filled($filters['school_level'] ?? null)
        ) {
            return response()->json(['schools' => []]);
        }

        $schools = $query
            ->orderBy('name')
            ->get(['code', 'name', 'level', 'province_code', 'commune_code']);

        return response()->json(['schools' => $schools]);
    }

    private function schoolLevelForGrade(int $grade): string
    {
        return match (true) {
            $grade <= 5 => 'TH',
            $grade <= 9 => 'THCS',
            default => 'THPT',
        };
    }

    private function eligibleSchoolLevelsForGrade(int $grade): array
    {
        $level = $this->schoolLevelForGrade($grade);

        return [
            'level' => $level,
            'multi_level' => match ($level) {
                'TH' => ['THCS', 'THPT'],
                'THCS' => ['THPT'],
                default => [],
            },
        ];
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

        $data = $request->validate(
            $this->rules($request, $isHouseholdHead, household: $household),
            $this->validationMessages(),
        );
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
        $data = $request->validate(
            $this->rules($request, $isHouseholdHead, $person, $education, $household),
            $this->validationMessages(),
        );
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

    private function rules(
        Request $request,
        bool $isHouseholdHead = false,
        ?Person $person = null,
        ?PersonEducation $education = null,
        ?Household $household = null,
    ): array {
        $user = $request->user();
        $personNameRule = "regex:/^[\\p{L}\\p{M}]+(?:[ '-][\\p{L}\\p{M}]+)*$/u";
        $rules = [
            'first_name' => ['required', 'string', 'max:100', $personNameRule],
            'last_name' => ['required', 'string', 'max:50', $personNameRule],
            'dob' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => 'required|in:NAM,NU',
            'ethnicity_code' => 'required|exists:ethnicities,code',
            'religion' => 'nullable|string|max:50',
            'priority_type' => 'nullable|string|max:100',
            'parent_name' => ['nullable', 'string', 'max:150', $personNameRule],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\\+?[0-9]+$/'],
            'note' => 'nullable|string|max:2000',
        ];

        $rules['relationship_with_head'] = $isHouseholdHead
            ? 'nullable|string|max:100'
            : ['required', 'string', 'max:100', Rule::notIn(['Chủ hộ'])];

        // chỉ cho phép những cái có
        $schoolYearRules = ['required', Rule::in($this->people->schoolYears())];
        if ($person?->exists) {
            $schoolYearRules[] = function ($attribute, $value, $fail) use ($person, $education): void {
                $schoolYearId = SchoolYear::findByLabel((string) $value)?->id;
                if ($schoolYearId === null) {
                    return;
                }

                $query = $person->educations()->where('school_year_id', $schoolYearId);
                if ($education?->exists) {
                    $query->whereKeyNot($education->id);
                }
                if ($query->exists()) {
                    $fail('Nhân khẩu đã có thông tin học tập trong năm học này.');
                }
            };
        }

        $notFutureYear = function ($attribute, $value, $fail) {
            preg_match_all('/(?<!\d)\d{4}(?!\d)/', (string) $value, $matches);
            foreach ($matches[0] as $year) {
                if ((int) $year > now()->year) {
                    $fail('Năm không được lớn hơn ' . now()->year . '.');

                    return;
                }
            }
        };
        $notFutureEducationYear = function ($attribute, $value, $fail) use ($notFutureYear) {
            $currentYear = now()->year;
            if (
                preg_match('/^(\d{4})-(\d{4})$/', (string) $value, $matches) === 1
                && (int) $matches[1] === $currentYear
                && (int) $matches[2] === $currentYear + 1
            ) {
                return;
            }

            $notFutureYear($attribute, $value, $fail);
        };
        $classWithinGradeRange = function ($attribute, $value, $fail): void {
            if (
                preg_match('/^\s*(?:lớp\s*)?(\d+)/iu', (string) $value, $matches) === 1
                && (int) $matches[1] > 12
            ) {
                $fail('Khối lớp không được lớn hơn lớp 12.');
            }
        };

        $schoolProvinceRules = ['nullable', 'exists:provinces,code'];
        $schoolCommuneRules = ['nullable', Rule::exists('communes', 'code')
            ->where('province_code', $request->input('school_province_code'))];
        if ($household?->province_code !== null) {
            $schoolProvinceRules[] = Rule::in([$household->province_code]);
        }
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            if ($user->province_code !== null) {
                $schoolProvinceRules[] = Rule::in([$user->province_code]);
            }
        }

        $schoolExistsRule = Rule::exists('schools', 'code')
            ->where('province_code', $household?->province_code);
        $currentClass = (string) $request->input('education.current_class', '');
        $preschoolClass = preg_match('/^\s*MN\s*\d/iu', $currentClass) === 1;
        if ($preschoolClass) {
            $schoolExistsRule->where('level', 'MN');
        }
        $academicBlock = $preschoolClass ? null : ($this->gradeFromClassName(
            $currentClass,
        ) ?? filter_var(
            $request->input('education.academic_block'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 12]],
        ));
        if ($academicBlock !== false && $academicBlock !== null) {
            $schoolLevels = $this->eligibleSchoolLevelsForGrade((int) $academicBlock);
            $schoolExistsRule->where(function ($query) use ($schoolLevels): void {
                $query->where('level', $schoolLevels['level']);
                if ($schoolLevels['multi_level'] !== []) {
                    $query->orWhere(function ($multiLevelQuery) use ($schoolLevels): void {
                        $multiLevelQuery
                            ->where('type', 'multi_level')
                            ->whereIn('level', $schoolLevels['multi_level']);
                    });
                }
            });
        }

        $rules += [
            'school_province_code' => $schoolProvinceRules,
            'school_commune_code' => $schoolCommuneRules,
            'education.school_year' => $schoolYearRules,
            'education.academic_block' => 'nullable|string|max:50',
            'education.current_class' => ['nullable', 'string', 'max:50', $classWithinGradeRange],
            'education.school_code' => [
                'nullable',
                $schoolExistsRule,
            ],
            'education.graduation_level' => ['nullable', Rule::in(['MN', 'TH', 'THCS', 'THPT'])],
            'education.is_complementary' => 'nullable|boolean',
            'education.graduation_year' => ['nullable', 'string', 'max:20', 'regex:/^\\d{4}(?:-\\d{4})?$/', $notFutureEducationYear],
            'education.vocational_grad_level' => 'nullable|string|max:50',
            'education.vocational_grad_year' => ['nullable', 'string', 'max:20', 'regex:/^\\d{4}(?:-\\d{4})?$/', $notFutureEducationYear],
            'education.finished_class' => ['nullable', 'string', 'max:20', $classWithinGradeRange],
            'education.finished_year' => ['nullable', 'string', 'max:20', 'regex:/^\\d{4}(?:-\\d{4})?$/', $notFutureEducationYear],
            'education.dropped_class' => ['nullable', 'string', 'max:20', $classWithinGradeRange],
            'education.dropped_year' => ['nullable', 'string', 'max:20', 'regex:/^\\d{4}(?:-\\d{4})?$/', $notFutureEducationYear],
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

    private function validationMessages(): array
    {
        return [
            'first_name.regex' => 'Tên chỉ được chứa chữ cái, khoảng trắng, dấu nháy hoặc dấu gạch nối.',
            'last_name.regex' => 'Họ và tên đệm chỉ được chứa chữ cái, khoảng trắng, dấu nháy hoặc dấu gạch nối.',
            'parent_name.regex' => 'Họ tên cha hoặc mẹ chỉ được chứa chữ cái, khoảng trắng, dấu nháy hoặc dấu gạch nối.',
            'dob.date_format' => 'Ngày sinh không đúng định dạng.',
            'phone.regex' => 'Số điện thoại chỉ gồm chữ số và có thể bắt đầu bằng dấu +, ví dụ +84912345678.',
            'education.graduation_year.regex' => 'Năm tốt nghiệp phải có dạng YYYY hoặc YYYY-YYYY.',
            'education.vocational_grad_year.regex' => 'Năm tốt nghiệp nghề phải có dạng YYYY hoặc YYYY-YYYY.',
            'education.finished_year.regex' => 'Năm hoàn thành lớp phải có dạng YYYY hoặc YYYY-YYYY.',
            'education.dropped_year.regex' => 'Năm bỏ học phải có dạng YYYY hoặc YYYY-YYYY.',
        ];
    }

    private function gradeFromClassName(mixed $className): ?int
    {
        if (! preg_match('/^\s*(?:lớp\s*)?(\d{1,2})(?=\D|$)/iu', (string) $className, $match)) {
            return null;
        }

        $grade = (int) $match[1];

        return $grade >= 1 && $grade <= 12 ? $grade : null;
    }

    private function assertHouseholdAccess(Request $request, Household $household): void
    {
        abort_unless(DataAccessScope::canAccessHousehold($request->user(), $household), 404);
    }
}
