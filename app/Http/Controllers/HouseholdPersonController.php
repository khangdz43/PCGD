<?php

namespace App\Http\Controllers;

use App\Models\Ethnicity;
use App\Models\Household;
use App\Models\Person;
use App\Models\PersonDisability;
use App\Models\PersonEducation;
use App\Models\Province;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HouseholdPersonController extends Controller
{
    public function create(Request $request, Household $household)
    {
        // is_head 1 thì là true
        $isHouseholdHead = $request->boolean('is_head');
        $headPerson = $household->persons()->where('relationship_with_head', 'Chủ hộ')->first();
        $headPersonExists = $headPerson !== null; // check có chủ hộ không (true false)


        // excep
        if ($isHouseholdHead && $headPersonExists) {
            return redirect()->route('households.show', $household)->with('warning', 'Hộ này đã có hồ sơ chủ hộ.');
        }

        if (!$isHouseholdHead && !$headPersonExists) {
            return redirect()->route('households.members.create', [$household, 'is_head' => 1])
                ->with('warning', 'Cần nhập hồ sơ chủ hộ trước khi thêm thành viên khác.');
        }

        $person = new Person();
        $person->gender = 'NAM';
        if ($isHouseholdHead) {
            $person->first_name = $household->head_first_name;
            $person->last_name = $household->head_last_name;
            $person->relationship_with_head = 'Chủ hộ';
        } elseif ($headPerson) {
            $person->ethnicity_code = $headPerson->ethnicity_code;
        }
        // mặc định tên của chủ hộ khi thêm vào bảng person
        $formData = $this->formData($person, $isHouseholdHead, $household->school_year);
        // lúc thêm thành viên mới có các thông tin của chủ hộ , person (Nam) , formdataa (năm)
        return view('households.members.create', array_merge(compact('household', 'person', 'isHouseholdHead'), $formData));
    }

    public function store(Request $request, Household $household)
    {
        $isHouseholdHead = $request->boolean('is_head');
        $headPersonExists = $household->persons()->where('relationship_with_head', 'Chủ hộ')->exists();

        // xu li excep
        if (!$isHouseholdHead && !$headPersonExists) {
            return redirect()->route('households.members.create', [$household, 'is_head' => 1])
                ->with('warning', 'Cần nhập hồ sơ chủ hộ trước khi thêm thành viên khác.');
        }

        if ($isHouseholdHead && $headPersonExists) {
            return redirect()->route('households.show', $household)->with('warning', 'Hộ này đã có hồ sơ chủ hộ.');
        }

        $data = $request->validate($this->rules($request, $isHouseholdHead));
        if ($isHouseholdHead) {
            $data['last_name'] = $household->head_last_name;
            $data['first_name'] = $household->head_first_name;
        }

        // tách data thành 3 mảng riêng biệt
        [$personData, $educationData, $disabilityData] = $this->splitFormData($data);
        $personData['relationship_with_head'] = $isHouseholdHead ? 'Chủ hộ' : $personData['relationship_with_head'];

        // thêm vào 3 bảng 
        DB::transaction(function () use ($household, $personData, $educationData, $disabilityData) {
            $person = $household->persons()->create($personData);
            $person->educations()->create($educationData);
            $person->disability()->create($disabilityData);
        });

        return redirect()->route('households.show', $household)->with('success', $isHouseholdHead
            ? 'Đã thêm chủ hộ vào danh sách nhân khẩu. Bây giờ có thể thêm các thành viên khác.'
            : 'Đã thêm thành viên vào hộ.');
    }

    public function show(Household $household, int $personId)
    {
        $household->load('province', 'commune', 'village');
        $person = $household->persons()
            ->with(['ethnicity', 'educations.school', 'disability'])
            ->findOrFail($personId);

        return view('households.members.show', compact('household', 'person'));
    }

    public function edit(Household $household, int $personId)
    {
        $person = $household->persons()->findOrFail($personId);
        $isHouseholdHead = $person->relationship_with_head === 'Chủ hộ';
        $formData = $this->formData($person, $isHouseholdHead, $household->school_year);

        return view('households.members.edit', array_merge(compact('household', 'person', 'isHouseholdHead'), $formData));
    }

    public function update(Request $request, Household $household, int $personId)
    {
        // người trong hộ này 
        $person = $household->persons()->findOrFail($personId);
        $isHouseholdHead = $person->relationship_with_head === 'Chủ hộ';
        // tìm năm học mới nhất vì trong bảng education sẽ có nhiều năm (varchar )
        $education = $person->educations()->orderByDesc('school_year')->first();
        // validate
        $data = $request->validate($this->rules($request, $isHouseholdHead, $person, $education));
        if ($isHouseholdHead) {
            // nếu education tồn tại thì lấy năm ra , còn null thì lấy năm của chủ hộ còn k lấy năm hiện tại ở form sửa
            $data['education']['school_year'] = $education?->school_year ?: ($household->school_year ?: $this->currentSchoolYear());
        }
        [$personData, $educationData, $disabilityData] = $this->splitFormData($data);

        if ($isHouseholdHead) {
            $personData['relationship_with_head'] = 'Chủ hộ';
            DB::transaction(function () use ($person, $household, $personData, $education, $educationData, $disabilityData) {
                $person->update($personData);
                // nếu chủ hộ sửa thì phải đồng bộ
                $household->update([
                    'head_first_name' => $personData['first_name'],
                    'head_last_name' => $personData['last_name'],
                ]);
                $this->saveEducationAndDisability($person, $education, $educationData, $disabilityData);
            });
        } else {
            // thành viên thường thì k cần
            DB::transaction(function () use ($person, $personData, $education, $educationData, $disabilityData) {
                $person->update($personData);
                $this->saveEducationAndDisability($person, $education, $educationData, $disabilityData);
            });
        }

        return redirect()->route('households.show', $household)->with('success', 'Đã cập nhật thành viên.');
    }

    public function destroy(Household $household, int $personId)
    {
        $person = $household->persons()->findOrFail($personId);

        if (
            $person->relationship_with_head === 'Chủ hộ'
            && $household->persons()->where('relationship_with_head', '!=', 'Chủ hộ')->exists()
        ) {
            return redirect()->route('households.show', $household)
                ->with('warning', 'Hãy xóa các thành viên khác trước khi xóa hồ sơ chủ hộ.');
        }

        $person->delete();

        return redirect()->route('households.show', $household)->with('success', $person->relationship_with_head === 'Chủ hộ'
            ? 'Đã xóa hồ sơ chủ hộ. Cần nhập lại chủ hộ trước khi thêm thành viên.'
            : 'Đã xóa thành viên khỏi hộ.');
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'person_ids' => ['required', 'array', 'min:1'],
            'person_ids.*' => ['integer', 'distinct', 'exists:persons,id'],
        ]);
        // lấy các id person mà client tick trong list ids 
        $persons = Person::whereIn('id', $data['person_ids'])->get();
        foreach ($persons as $person) {
            if (
                // check xem người này có phải chủ hộ không
                $person->relationship_with_head === 'Chủ hộ'
                // chủ hộ còn tghanfh viên trong gia đình
                && Person::where('household_id', $person->household_id)
                ->where('relationship_with_head', '!=', 'Chủ hộ')
                ->exists()
            ) {
                return back()->with('warning', 'Không thể xóa chủ hộ khi hộ vẫn còn thành viên. Hãy xóa các thành viên khác trước.');
            }
        }
        // xóa các thằng hợp lệ
        DB::transaction(function () use ($persons) {
            Person::whereIn('id', $persons->modelKeys())->delete();
        });
        return back()->with('success', "Đã xóa {$persons->count()} thành viên đã chọn.");
    }

    private function rules(Request $request, bool $isHouseholdHead = false, ?Person $person = null, ?PersonEducation $education = null): array
    {
        $rules = [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:50',
            'dob' => 'required|date',
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
        $schoolYearRules = ['required', Rule::in($this->schoolYears())];
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

        $rules += [
            'school_province_code' => 'nullable|exists:provinces,code',
            'school_commune_code' => 'nullable|exists:communes,code',
            'education.school_year' => $schoolYearRules,
            'education.academic_block' => 'nullable|string|max:50',
            'education.current_class' => 'nullable|string|max:50',
            'education.school_code' => [
                'nullable',
                Rule::exists('schools', 'code')
                    ->where('province_code', $request->input('school_province_code'))
                    ->where('commune_code', $request->input('school_commune_code')),
            ],
            'education.graduation_level' => ['nullable', Rule::in(['MN', 'TH', 'THCS', 'THPT'])],
            'education.is_complementary' => 'nullable|boolean',
            'education.graduation_year' => 'nullable|string|max:20',
            'education.vocational_grad_level' => 'nullable|string|max:50',
            'education.vocational_grad_year' => 'nullable|string|max:20',
            'education.finished_class' => 'nullable|string|max:20',
            'education.finished_year' => 'nullable|string|max:20',
            'education.dropped_class' => 'nullable|string|max:20',
            'education.dropped_year' => 'nullable|string|max:20',
            'education.literacy_current_class' => 'nullable|string|max:20',
            'education.literacy_completed_class' => 'nullable|string|max:20',
            'education.literacy_relapse_level' => 'nullable|integer|between:1,2',
            'education.learning_capacity' => 'nullable|string|max:50',
        ];

        foreach ($this->disabilityBooleanFields() as $field) {
            // toàn bộ fieild ở disbility lấy ở method trên cho null và boolean
            $rules["disability.{$field}"] = 'nullable|boolean';
        }

        $rules += [
            'disability.special_circumstance' => ['nullable', Rule::in(['Chuyển đến', 'Chuyển đi'])],
            'disability.special_circumstance_detail' => 'nullable|string|max:2000',
        ];

        return $rules;
    }

    private function formData(Person $person, bool $isHouseholdHead, ?string $householdSchoolYear = null): array
    {
        $education = $person->exists
            ? $person->educations()->with('school')->orderByDesc('school_year')->first()
            : null;
        // nếu education null
        $education ??= new PersonEducation(['school_year' => $householdSchoolYear ?: $this->currentSchoolYear()]);

        $disability = $person->exists ? $person->disability()->first() : null;
        $disability ??= new PersonDisability();

        return [
            'education' => $education,
            'disability' => $disability,
            'ethnicities' => Ethnicity::orderBy('name')->get(['code', 'name']),
            'provinces' => Province::orderBy('name')->get(['code', 'name']),
            'schools' => School::orderBy('name')->get(['code', 'name', 'level', 'province_code', 'commune_code']),
        ];
    }

    private function splitFormData(array $data): array
    {
        $personData = $data;
        // bỏ bọn data k thuộc mảng person
        unset($personData['education'], $personData['disability'], $personData['school_province_code'], $personData['school_commune_code']);

        $educationData = $data['education'];
        $educationData['is_complementary'] = (bool) ($educationData['is_complementary'] ?? false);

        $disabilityData = $data['disability'] ?? [];
        foreach ($this->disabilityBooleanFields() as $field) {
            $disabilityData[$field] = (bool) ($disabilityData[$field] ?? false);
        }

        return [$personData, $educationData, $disabilityData];
    }

    private function saveEducationAndDisability(Person $person, ?PersonEducation $education, array $educationData, array $disabilityData): void
    {
        // education không null và tồn tại
        if ($education?->exists) {
            $education->update($educationData);
        } else {
            // th ng dùng vào thêm 
            $person->educations()->create($educationData);
        }

        $person->disability()->updateOrCreate([], $disabilityData);
    }

    private function disabilityBooleanFields(): array
    {
        return [
            'mobility_disability',
            'hearing_speech_disability',
            'visual_disability',
            'mental_disability',
            'intellectual_disability',
            'learning_disability',
            'autism',
            'other_disability',
            'has_disability_cert',
            'can_study',
        ];
    }

    private function currentSchoolYear(): string
    {
        $year = now()->year;
        $startYear = now()->month >= 8 ? $year : $year - 1;

        return $startYear . '-' . ($startYear + 1);
    }

    private function schoolYears(): array
    {
        $lastSchoolYear = now()->month >= 8 ? now()->year : now()->year - 1;

        return array_map(
            fn(int $year): string => "{$year}-" . ($year + 1),
            range(2020, $lastSchoolYear)
        );
    }
}
