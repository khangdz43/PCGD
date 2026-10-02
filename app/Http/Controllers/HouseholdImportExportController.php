<?php

namespace App\Http\Controllers;

use App\Models\Ethnicity;
use App\Models\Household;
use App\Models\ImportLog;
use App\Models\Person;
use App\Models\Province;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Throwable;
use \PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class HouseholdImportExportController extends Controller
{
    public function create()
    {
        $provinces = Province::orderBy('name')->get(['code', 'name']);
        return view('households.import', compact('provinces'));
    }

    public function template()
    {
        $path = base_path('file-mau.XLS');
        abort_unless(is_file($path), 404, 'Không tìm thấy file mẫu Excel.');
        return response()->download($path, 'file-mau.XLS');
    }




    public function import(Request $request)
    {
        $data = $request->validate([
            // validate file max khoảng 20mb
            'file' => 'required|file|mimes:xls,xlsx|max:20480',
            'school_year' => ['required', Rule::in($this->schoolYears())],
            // các tỉnh thành phải có trong db
            'province_code' => 'required|exists:provinces,code',
            'commune_code' => [
                'required',
                Rule::exists('communes', 'code')->where('province_code', $request->input('province_code')),
            ],
            'village_code' => [
                'nullable',
                Rule::exists('villages', 'code')->where('commune_code', $request->input('commune_code')),
            ],
        ]);

        try {
            // $data['file']->getRealPath() lấy path thật của file , song tạo object đọc excel
            $reader = IOFactory::createReaderForFile($data['file']->getRealPath());
            // đọc toàn bộ file excel vào bộ nhớ
            $spreadsheet = $reader->load($data['file']->getRealPath());
            //  lấy worksheet chứa dữ liệu
            $sheet = $this->dataSheet($spreadsheet);
        } catch (Throwable) {
            return back()->withErrors(['file' => 'Không đọc được file Excel. Hãy tải và nhập theo file mẫu.'])->withInput();
        }

        $ethnicityCodes = [];
        foreach (Ethnicity::query()->get(['code', 'name']) as $ethnicity) {
            // lưu mảng code của dân tộc để cho vào db
            $ethnicityCodes[$this->normalize($ethnicity->name)] = $ethnicity->code;
        }
        // chỉ lấy cột code 
        $schoolCodes = School::query()->pluck('code')->flip();
        $schoolYear = $data['school_year'];
        $groups = [];
        $errors = [];
        $newSchoolCodes = [];
        $totalRows = 0;

        for ($row = 5; $row <= $sheet->getHighestDataRow(); $row++) {
            // map các trường vào cell
            $householdCode = $this->cell($sheet, 'O' . $row);
            $lastName = $this->cell($sheet, 'C' . $row);
            $firstName = $this->cell($sheet, 'D' . $row);
            // bỏ qua các dòng trống k có dữ liệu
            if ($householdCode === '' && $lastName === '' && $firstName === '') {
                continue;
            }

            if ($householdCode === '' || $lastName === '' || $firstName === '') {
                $errors[] = "Dòng {$row}: cần có Số phiếu, Họ đệm và Tên.";
                continue;
            }

            $ethnicityName = $this->cell($sheet, 'I' . $row);
            // nếu trống để code là null còn cps trog mảng kia thì trả ra cái code để lưu code dân tộc 
            $ethnicityCode = $ethnicityName === '' ? null : ($ethnicityCodes[$this->normalize($ethnicityName)] ?? null);
            if ($ethnicityName === '') {
                $errors[] = "Dòng {$row}: cần có dân tộc.";
            } elseif ($ethnicityCode === null) {
                $errors[] = "Dòng {$row}: dân tộc '{$ethnicityName}' chưa có trong danh mục.";
            }

            $birthDate = $this->birthDate($sheet, $row);

            if ($birthDate['date'] === null) {
                $errors[] = "Dòng {$row}: cần có ngày, tháng và năm sinh hợp lệ.";
            } elseif ($birthDate['date'] > now()->toDateString()) {
                $errors[] = "Dòng {$row}: ngày sinh không được ở tương lai.";
            }
            // 
            $schoolCode = $this->cell($sheet, 'V' . $row);
            // nếu chưa có mã trường thì tạo mã mới
            if ($schoolCode !== '' && !$schoolCodes->has($schoolCode)) {
                $newSchoolCodes[$schoolCode] = true;
            }

            $record = [
                'row' => $row,
                'household_code' => $householdCode,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'dob' => $birthDate,
                // tick là nữ ko nam
                'gender' => $this->isMarked($this->cell($sheet, 'H' . $row)) ? 'NU' : 'NAM',
                'ethnicity_code' => $ethnicityCode,

                'religion' => $this->nullableCell($sheet, 'J' . $row),
                'priority_type' => $this->nullableCell($sheet, 'K' . $row),
                'head_last_name' => $this->cell($sheet, 'L' . $row),
                'head_first_name' => $this->cell($sheet, 'M' . $row),
                'address' => $this->nullableCell($sheet, 'N' . $row),
                'residence_type' => $this->cell($sheet, 'P' . $row),
                'residence_status' => $this->nullableCell($sheet, 'Q' . $row),
                'academic_block' => $this->nullableCell($sheet, 'R' . $row),
                'current_class' => $this->nullableCell($sheet, 'S' . $row),
                'school_code' => $schoolCode ?: null,
                'graduation_level' => $this->nullableCell($sheet, 'W' . $row),
                'is_complementary' => $this->isMarked($this->cell($sheet, 'X' . $row)),
                'graduation_year' => $this->nullableCell($sheet, 'Y' . $row),
                'vocational_grad_level' => $this->nullableCell($sheet, 'Z' . $row),
                'vocational_grad_year' => $this->nullableCell($sheet, 'AB' . $row),
                'finished_class' => $this->nullableCell($sheet, 'AC' . $row),
                'finished_year' => $this->nullableCell($sheet, 'AD' . $row),
                'dropped_class' => $this->nullableCell($sheet, 'AE' . $row),
                'dropped_year' => $this->nullableCell($sheet, 'AF' . $row),
                'literacy_current_class' => $this->nullableCell($sheet, 'AG' . $row),
                'literacy_completed_class' => $this->nullableCell($sheet, 'AH' . $row),
                'literacy_relapse_level' => $this->nullableCell($sheet, 'AI' . $row),
                'disability' => [
                    'mobility_disability' => $this->isMarked($this->cell($sheet, 'AJ' . $row)),
                    'hearing_speech_disability' => $this->isMarked($this->cell($sheet, 'AK' . $row)),
                    'visual_disability' => $this->isMarked($this->cell($sheet, 'AL' . $row)),
                    'mental_disability' => $this->isMarked($this->cell($sheet, 'AM' . $row)),
                    'intellectual_disability' => $this->isMarked($this->cell($sheet, 'AN' . $row)),
                    'learning_disability' => $this->isMarked($this->cell($sheet, 'AO' . $row)),
                    'autism' => $this->isMarked($this->cell($sheet, 'AP' . $row)),
                    'other_disability' => $this->isMarked($this->cell($sheet, 'AQ' . $row)),
                    'has_disability_cert' => $this->isMarked($this->cell($sheet, 'AR' . $row)),
                    'can_study' => $this->isMarked($this->cell($sheet, 'AS' . $row)),
                    'special_circumstance' => $this->nullableCell($sheet, 'AT' . $row),
                    'special_circumstance_detail' => $this->nullableCell($sheet, 'AU' . $row),
                ],
                'relationship_with_head' => $this->nullableCell($sheet, 'AV' . $row),
                'parent_name' => $this->nullableCell($sheet, 'AW' . $row),
                'phone' => $this->nullableCell($sheet, 'AX' . $row),
                'note' => $this->nullableCell($sheet, 'AY' . $row),
            ];

            foreach (
                [
                    'graduation_year' => 'Năm tốt nghiệp',
                    'vocational_grad_year' => 'Năm tốt nghiệp nghề',
                    'finished_year' => 'Năm học xong',
                    'dropped_year' => 'Năm bỏ học',
                ] as $field => $label
            ) {
                if ($this->hasFutureYear($record[$field])) {
                    $errors[] = "Dòng {$row}: {$label} không được lớn hơn " . now()->year . '.';
                }
            }

            // lấy value của key householdCode trong mảng groups
            $recordsByCode = $groups[$householdCode] ?? [];
            // push thêm nhân khẩu vào 
            $recordsByCode[] = $record;
            //value của mảng mà key đó sẽ bằng mảng mới
            $groups[$householdCode] = $recordsByCode;
            $totalRows++;
        }

        if ($totalRows === 0) {
            $errors[] = 'Không tìm thấy dòng dữ liệu. Dữ liệu cần bắt đầu từ dòng 5.';
        }

        if ($errors !== []) {
            return back()->withErrors(['file' => implode(' ', array_slice($errors, 0, 10))])->withInput();
        }

        // $groups = [
        //     'HK001' => [
        //         ['name' => 'Nguyễn Văn A', 'relationship' => 'Chủ hộ', 'row' => 5],
        //         ['name' => 'Trần Thị B',   'relationship' => 'Vợ',     'row' => 6],
        //     ],
        //     'HK002' => [
        //         ['name' => 'Lê Văn C',     'relationship' => 'Chủ hộ', 'row' => 7],
        //     ]
        // ];


        foreach ($groups as $householdCode => $records) {
            // records là value của từng householdCode(key)
            $headRecords = [];
            // lấy value của mảng[key = headlastname]
            $headLastName = $this->firstValue($records, 'head_last_name');
            $headFirstName = $this->firstValue($records, 'head_first_name');

            foreach ($records as $record) {
                // check tìm ra ông chủ hộ
                $isHead = $this->normalize((string) $record['relationship_with_head']) === $this->normalize('Chủ hộ')
                    || ($headLastName !== '' && $headFirstName !== '' && $this->normalize($record['last_name']) === $this->normalize($headLastName)
                        && $this->normalize($record['first_name']) === $this->normalize($headFirstName));

                if ($isHead) {
                    $headRecords[] = $record;
                }
            }

            if (count($headRecords) > 1) {
                $headRows = array_column($headRecords, 'row');
                $errors[] = "Phiếu '{$householdCode}' có nhiều chủ hộ ở dòng " . implode(', ', $headRows) . '.';
            } elseif (count($headRecords) === 1) {
                $headRecord = $headRecords[0];
                foreach ($records as $record) {
                    if ($record['row'] === $headRecord['row'] || $record['dob']['date'] === null) {
                        continue;
                    }

                    $ageError = $this->relationshipAgeError(
                        (string) $record['relationship_with_head'],
                        $record['dob']['date'],
                        $headRecord['dob']['date'],
                    );
                    if ($ageError !== null) {
                        $errors[] = "Dòng {$record['row']}: {$ageError}";
                    }
                }
            }
        }

        if ($errors !== []) {

            return back()->withErrors(['file' => implode(' ', array_slice($errors, 0, 10))])->withInput();
        }

        // check query có tồn tại cái mã hộ này k
        $existingCode = Household::query()
            ->where('school_year', $schoolYear)
            ->whereIn('household_code', array_keys($groups))
            ->value('household_code');
        if ($existingCode !== null) {
            return back()->withErrors(['file' => "Mã phiếu '{$existingCode}' đã tồn tại. Không nhập file để tránh ghi đè hoặc nhân đôi dữ liệu."])->withInput();
        }

        $importLog = ImportLog::create([
            'file_name' => $data['file']->getClientOriginalName(),
            'total_rows' => $totalRows,
            'status' => 'PROCESSING',
        ]);

        try {
            // dùng transaction để rollback nếu có err
            DB::transaction(function () use ($groups, $data, $schoolYear, $importLog, $newSchoolCodes, &$totalRows) {

                // thêm trường mới vào db nếu chưa có thực tế thì phân quyền ....
                foreach (array_keys($newSchoolCodes) as $schoolCode) {
                    School::firstOrCreate(
                        ['code' => $schoolCode],
                        [
                            'name' => null,
                            'level' => null,
                            'province_code' => $data['province_code'],
                            'commune_code' => $data['commune_code'],
                        ]
                    );
                }
                // thêm vào household
                foreach ($groups as $householdCode => $records) {
                    $headLastName = $this->firstValue($records, 'head_last_name');
                    $headFirstName = $this->firstValue($records, 'head_first_name');
                    $firstRecord = $records[0];
                    $household = Household::create([
                        'household_code' => $householdCode,
                        'school_year' => $schoolYear,
                        'head_last_name' => $headLastName,
                        'head_first_name' => $headFirstName,
                        'province_code' => $data['province_code'],
                        'commune_code' => $data['commune_code'],
                        'village_code' => $data['village_code'] ?? null,
                        'address' => $this->firstValue($records, 'address'),
                        'residence_type' => $this->residenceType($this->firstValue($records, 'residence_type')),
                        'residence_status' => $this->firstValue($records, 'residence_status'),
                        'import_log_id' => $importLog->id,
                    ]);

                    $hasHeadPerson = false;
                    // thêm vào person
                    foreach ($records as $record) {
                        $isHead = $this->normalize((string) $record['relationship_with_head']) === $this->normalize('Chủ hộ')
                            || ($headLastName !== '' && $headFirstName !== ''
                                && $this->normalize($record['last_name']) === $this->normalize($headLastName)
                                && $this->normalize($record['first_name']) === $this->normalize($headFirstName));
                        $relationship = $isHead ? 'Chủ hộ' : $record['relationship_with_head'];
                        $hasHeadPerson = $hasHeadPerson || $isHead;

                        $person = $household->persons()->create([
                            'import_log_id' => $importLog->id,
                            'last_name' => $record['last_name'],
                            'first_name' => $record['first_name'],
                            'dob' => $record['dob']['date'],
                            'dob_str' => $record['dob']['raw'],
                            'gender' => $record['gender'],
                            'ethnicity_code' => $record['ethnicity_code'],
                            'religion' => $record['religion'],
                            'priority_type' => $record['priority_type'],
                            'relationship_with_head' => $relationship,
                            'parent_name' => $record['parent_name'],
                            'phone' => $record['phone'],
                            'note' => $record['note'],
                        ]);

                        if ($this->hasEducationData($record)) {
                            // lưu vào education
                            $person->educations()->create([
                                'import_log_id' => $importLog->id,
                                'school_year' => $schoolYear,
                                'academic_block' => $record['academic_block'],
                                'current_class' => $record['current_class'],
                                'school_code' => $record['school_code'],
                                'graduation_level' => $record['graduation_level'],
                                'is_complementary' => $record['is_complementary'],
                                'graduation_year' => $record['graduation_year'],
                                'vocational_grad_level' => $record['vocational_grad_level'],
                                'vocational_grad_year' => $record['vocational_grad_year'],
                                'finished_class' => $record['finished_class'],
                                'finished_year' => $record['finished_year'],
                                'dropped_class' => $record['dropped_class'],
                                'dropped_year' => $record['dropped_year'],
                                'literacy_current_class' => $record['literacy_current_class'],
                                'literacy_completed_class' => $record['literacy_completed_class'],
                                'literacy_relapse_level' => $record['literacy_relapse_level'],
                            ]);
                        }
                        // khuyết tật
                        if ($this->hasDisabilityData($record['disability'])) {
                            $person->disability()->create($record['disability']);
                        }
                    }
                    // 
                    if (!$hasHeadPerson && ($headLastName !== '' || $headFirstName !== '')) {
                        // thêm chủ hộ vào person
                        $household->persons()->create([
                            'import_log_id' => $importLog->id,
                            'last_name' => $headLastName ?: $firstRecord['last_name'],
                            'first_name' => $headFirstName ?: $firstRecord['first_name'],
                            'gender' => 'NAM',
                            'relationship_with_head' => 'Chủ hộ',
                        ]);
                    }
                }
            });
        } catch (Throwable $exception) {
            $importLog->update([
                'status' => 'FAILED',
                'error_rows' => $totalRows,
                'error_details' => [['message' => $exception->getMessage()]],
            ]);

            return back()->withErrors(['file' => 'Nhập dữ liệu thất bại: ' . $exception->getMessage()])->withInput();
        }

        // update status ở bảng import_logs
        $importLog->update([
            'status' => 'SUCCESS',
            'success_rows' => $totalRows,
        ]);

        return redirect()->to(route('households.index', [], false))->with('success', "Đã nhập {$totalRows} dòng nhân khẩu từ file Excel.");
    }







    public function export(Request $request)
    {
        $path = base_path('file-mau.XLS');
        abort_unless(is_file($path), 404, 'Không tìm thấy file mẫu Excel.');

        $filters = $request->validate([
            // sometimes : validate khi xuất hiện payload gửi lên còn k thì ko validate trường này r bỏ qua
            // khi người dùng k tick cái checkbox nào
            'household_ids' => ['sometimes', 'array'],
            'household_ids.*' => ['integer', 'distinct', 'exists:households,id'],
            'school_code' => ['nullable', 'string'],
            'school_year' => ['nullable', 'string'],
        ]);

        // đọc file vào ram
        $reader = IOFactory::createReaderForFile($path);
        $spreadsheet = $reader->load($path);
        $sheet = $this->dataSheet($spreadsheet);
        // load song file mẫu
        // load hết các quan hệ liên quan
        $query = Household::with([
            'province',
            'commune',
            'village',
            'persons.ethnicity',
            'persons.disability',
            'persons.educations',
        ]);
        if ($request->filled('q')) {
            $search = $request->string('q')->trim()->toString();
            $query->where(fn($builder) => $builder
                ->where('household_code', 'like', "%{$search}%")
                ->orWhere('head_first_name', 'like', "%{$search}%")
                ->orWhere('head_last_name', 'like', "%{$search}%"));
        }
        if ($request->filled('school_code')) {
            $query->whereHas('persons.educations', function ($builder) use ($request) {
                $builder->where('school_code', $request->input('school_code'));
            });
        }
        if ($request->filled('school_year')) {
            $query->where('school_year', $request->input('school_year'));
        }
        foreach (['province_code', 'commune_code', 'village_code'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if (!empty($filters['household_ids'])) {
            $query->whereIn('id', $filters['household_ids']);
        }

        //  DATA MAPPING VÀO TỪNG CELL 
        $sequence = 1; //stt 1
        $row = 5; // ghi bắt đầu từ dog 5
        $templateLastRow = $sheet->getHighestRow();
        foreach ($query->orderBy('household_code')->get() as $household) {
            // lưu thông tin
            foreach ($household->persons as $person) {
                // map từng record từng cell
                $this->writeExportRow($sheet, $row++, $sequence++, $household, $person);
            }
        }
        // vượt giới hạn dòng ở mẫu 
        $lastExportRow = $row - 1;
        if ($lastExportRow > $templateLastRow) {
            $firstOverflowRow = $templateLastRow + 1;
            $lastColumnIndex = Coordinate::columnIndexFromString('AY');
            // copy định dạng 
            for ($columnIndex = 1; $columnIndex <= $lastColumnIndex; $columnIndex++) {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $sheet->duplicateStyle(
                    $sheet->getStyle($column . '5'),
                    $column . $firstOverflowRow . ':' . $column . $lastExportRow
                );
            }
            // chỉnh lại độ cao dòng ....
            $rowHeight = $sheet->getRowDimension(5)->getRowHeight();
            for ($overflowRow = $firstOverflowRow; $overflowRow <= $lastExportRow; $overflowRow++) {
                $sheet->getRowDimension($overflowRow)->setRowHeight($rowHeight);
            }
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xls($spreadsheet))->save('php://output');
        }, 'phieu-dieu-tra-' . now()->format('Ymd-His') . '.xls', [
            // tránh trình duyệt nó mở luôn dạng text
            'Content-Type' => 'application/vnd.ms-excel',
        ]);
    }

    // map từng record vào từng row trong excel
    private function writeExportRow(Worksheet $sheet, int $row, int $sequence, object $household, ?Person $person): void
    {
        $education = $person?->educations->sortByDesc('school_year')->first();
        $disability = $person?->disability;
        $date = $person?->dob ? strtotime((string) $person->dob) : false;
        $values = [
            'B' => $sequence,
            'C' => $person?->last_name ?? $household->head_last_name,
            'D' => $person?->first_name ?? $household->head_first_name,
            'E' => $date ? date('j', $date) : null,
            'F' => $date ? date('n', $date) : null,
            'G' => $date ? date('Y', $date) : null,
            'H' => $person?->gender === 'NU' ? 'X' : null,
            'I' => $person?->ethnicity?->name,
            'J' => $person?->religion,
            'K' => $person?->priority_type,
            'L' => $household->head_last_name,
            'M' => $household->head_first_name,
            'N' => $household->address ?: $household->village?->name,
            'O' => $household->household_code,
            'P' => match ($household->residence_type) {
                'TAM_TRU' => 'Tạm trú',
                'KHAC' => 'Khác',
                default => 'Thường trú',
            },
            'Q' => $household->residence_status,
            'R' => $education?->academic_block,
            'S' => $education?->current_class,
            'V' => $education?->school_code,
            'W' => $education?->graduation_level,
            'X' => $education?->is_complementary ? 'X' : null,
            'Y' => $education?->graduation_year,
            'Z' => $education?->vocational_grad_level,
            'AB' => $education?->vocational_grad_year,
            'AC' => $education?->finished_class,
            'AD' => $education?->finished_year,
            'AE' => $education?->dropped_class,
            'AF' => $education?->dropped_year,
            'AG' => $education?->literacy_current_class,
            'AH' => $education?->literacy_completed_class,
            'AI' => $education?->literacy_relapse_level,
            'AJ' => $disability?->mobility_disability ? 'X' : null,
            'AK' => $disability?->hearing_speech_disability ? 'X' : null,
            'AL' => $disability?->visual_disability ? 'X' : null,
            'AM' => $disability?->mental_disability ? 'X' : null,
            'AN' => $disability?->intellectual_disability ? 'X' : null,
            'AO' => $disability?->learning_disability ? 'X' : null,
            'AP' => $disability?->autism ? 'X' : null,
            'AQ' => $disability?->other_disability ? 'X' : null,
            'AR' => $disability?->has_disability_cert ? 'X' : null,
            'AS' => $disability?->can_study ? 'X' : null,
            'AT' => $disability?->special_circumstance,
            'AU' => $disability?->special_circumstance_detail,
            'AV' => $person?->relationship_with_head ?? 'Chủ hộ',
            'AW' => $person?->parent_name,
            'AX' => $person?->phone,
            'AY' => $person?->note,
        ];

        foreach ($values as $column => $value) {
            if ($value !== null && $value !== '') {
                $sheet->setCellValueExplicit($column . $row, (string) $value, DataType::TYPE_STRING);
            }
        }
    }

    private function cell(Worksheet $sheet, string $coordinate): string
    {
        // tìm tới vị trí $coordinate lấy value
        return trim((string) $sheet->getCell($coordinate)->getFormattedValue());
    }

    private function dataSheet(Spreadsheet $spreadsheet): Worksheet
    {
        // lọc hết qua datasheet của file
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if (
                // phải đúng mẫu sheeet như này thì mới đúng là sheet mà chứa dữ liệu
                $this->normalize($this->cell($sheet, 'O2')) === 'sophieu'
                && $this->normalize($this->cell($sheet, 'AY2')) === 'ghichu'
            ) {
                return $sheet;
            }
        }

        return $spreadsheet->getActiveSheet();
    }

    // trả về string hoặc null
    private function nullableCell(Worksheet $sheet, string $coordinate): ?string
    {
        // trả ra value cell đó hoặc null 
        return $this->cell($sheet, $coordinate) ?: null;
    }

    private function birthDate(Worksheet $sheet, int $row): array
    {
        $day = $this->cell($sheet, 'E' . $row);
        $month = $this->cell($sheet, 'F' . $row);
        $year = $this->cell($sheet, 'G' . $row);
        // trống thì cho null date
        if ($day === '' && $month === '' && $year === '') {
            return ['date' => null, 'raw' => null];
        }

        if (
            // check là số
            ctype_digit($day) && ctype_digit($month) && ctype_digit($year)
            && checkdate((int) $month, (int) $day, (int) $year)
        ) {
            // định dạng chuẩn lưu vào mysql    y-m-d
            // raw để lưu chuỗi lỗi thô
            return ['date' => sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day), 'raw' => null];
        }

        return ['date' => null, 'raw' => substr("{$day}/{$month}/{$year}", 0, 20)];
    }

    private function hasFutureYear(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        preg_match_all('/(?<!\d)\d{4}(?!\d)/', $value, $matches);

        foreach ($matches[0] as $year) {
            if ((int) $year > now()->year) {
                return true;
            }
        }

        return false;
    }

    private function relationshipAgeError(string $relationship, string $birthDate, ?string $headBirthDate): ?string
    {
        if ($headBirthDate === null) {
            return null;
        }

        $relationship = $this->normalize($relationship);
        $isYounger = in_array($relationship, ['con', 'em', 'chau'], true) || str_starts_with($relationship, 'con');
        $isOlder = in_array($relationship, ['cha', 'bo', 'me', 'anh', 'chi', 'ong', 'ba', 'bac', 'co', 'chu', 'di', 'cau'], true)
            || str_starts_with($relationship, 'anh')
            || str_starts_with($relationship, 'chi');

        if ($isYounger && $birthDate <= $headBirthDate) {
            return 'Người có quan hệ con/em/cháu phải nhỏ tuổi hơn chủ hộ.';
        }

        if ($isOlder && $birthDate >= $headBirthDate) {
            return 'Người có quan hệ cha/mẹ/anh/chị/ông/bà phải lớn tuổi hơn chủ hộ.';
        }

        return null;
    }


    private function schoolYears(): array
    {
        // thường khai giảng năm mới vào t8 nên > 8 năm sau , 
        $lastSchoolYear = now()->month >= 8 ? now()->year : now()->year - 1;

        return array_map(
            fn(int $year): string => "{$year}-" . ($year + 1),
            range(2020, $lastSchoolYear)
        );
    }

    private function residenceType(string $value): string
    {
        return match ($this->normalize($value)) {
            'tamtru' => 'TAM_TRU',
            'khac' => 'KHAC',
            default => 'THUONG_TRU',
        };
    }

    // check ô đó có được đnáh dấu tick không
    private function isMarked(string $value): bool
    {
        return in_array($this->normalize($value), ['x', 'co', '1', 'yes', 'true'], true);
    }

    private function normalize(string $value): string
    {
        // chuyển mấy kí tự có dấu sang ascii
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        // xóa sạch ép chữ thường
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $ascii === false ? $value : $ascii));
    }

    private function firstValue(array $records, string $key): string
    {
        foreach ($records as $record) {
            if (!empty($record[$key])) {
                // trả ra phần tử đầu tiên vừa key truyền vào 
                // ép kiểu string 
                return (string) $record[$key];
            }
        }

        return '';
    }

    private function hasEducationData(array $record): bool
    {
        foreach (
            [
                'academic_block',
                'current_class',
                'school_code',
                'graduation_level',
                'graduation_year',
                'vocational_grad_level',
                'vocational_grad_year',
                'finished_class',
                'finished_year',
                'dropped_class',
                'dropped_year',
                'literacy_current_class',
                'literacy_completed_class',
                'literacy_relapse_level',
            ] as $field
        ) {
            if ($record[$field] !== null && $record[$field] !== '') {
                return true;
            }
        }

        return $record['is_complementary'];
    }

    private function hasDisabilityData(array $data): bool
    {
        foreach ($data as $value) {
            if ($value === true || ($value !== null && $value !== '')) {
                return true;
            }
        }

        return false;
    }
}
