<?php

namespace App\Services\Households;

use App\Models\Ethnicity;
use App\Models\Household;
use App\Models\ImportLog;
use App\Models\School;
use App\Models\User;
use App\Support\DataAccessScope;
use App\Support\HouseholdImportChunkReadFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

class HouseholdImportProcessingService
{
    public function process(ImportLog $importLog, User $user, UploadedFile $file): JsonResponse
    {
        $context = $importLog->context ?? [];
        $data = [
            'file' => $file,
            'school_year' => $context['school_year'] ?? null,
            'province_code' => $context['province_code'] ?? null,
            'commune_code' => $context['commune_code'] ?? null,
            'village_code' => $context['village_code'] ?? null,
        ];
        DataAccessScope::assertHouseholdLocation($user, $data);

        try {
            $reader = IOFactory::createReaderForFile($data['file']->getRealPath());
            $worksheetInfo = $reader->listWorksheetInfo($data['file']->getRealPath());
            $worksheetName = null;
            $visibleDataWorksheet = null;
            foreach ($worksheetInfo as $info) {
                if (
                    $visibleDataWorksheet === null
                    && $info['sheetState'] === 'visible'
                    && $info['totalColumns'] >= 15
                    && $info['totalRows'] >= 5
                ) {
                    $visibleDataWorksheet = $info['worksheetName'];
                }

                $reader->setLoadSheetsOnly([$info['worksheetName']]);
                $reader->setReadFilter(new HouseholdImportChunkReadFilter(2, 2));
                $headerWorkbook = $reader->load($data['file']->getRealPath());
                $headerSheet = $headerWorkbook->getSheet(0);
                $matchesTemplate = $this->normalize($this->cell($headerSheet, 'O2')) === 'sophieu'
                    && $this->normalize($this->cell($headerSheet, 'AY2')) === 'ghichu';
                $headerWorkbook->disconnectWorksheets();
                if ($matchesTemplate) {
                    $worksheetName = $info['worksheetName'];
                    break;
                }
            }
            $worksheetName ??= $visibleDataWorksheet;
            if ($worksheetName === null) {
                throw new \RuntimeException('File Excel không có trang tính dữ liệu.');
            }
            $worksheet = collect($worksheetInfo)->firstWhere('worksheetName', $worksheetName);
            $highestDataRow = (int) ($worksheet['totalRows'] ?? 0);
        } catch (Throwable) {
            $importLog->update([
                'status' => 'FAILED',
                'error_details' => [['message' => 'Không đọc được file Excel. Hãy tải và nhập theo file mẫu.']],
            ]);

            return response()->json(['message' => 'Không đọc được file Excel. Hãy tải và nhập theo file mẫu.'], 422);
        }

        $ethnicityCodes = [];
        foreach (Ethnicity::query()->get(['code', 'name']) as $ethnicity) {
            // lưu mảng code của dân tộc để cho vào db
            $ethnicityCodes[$this->normalize($ethnicity->name)] = $ethnicity->code;
        }
        // Lưu cả xã của trường để từ chối mã trường thuộc ngoài xã đang nhập.
        $schoolCommuneCodes = School::query()->pluck('commune_code', 'code');
        $schoolYear = $data['school_year'];
        $groups = [];
        $errors = [];
        $totalRows = 0;
        $importLog->update([
            'status' => 'PROCESSING',
            'total_rows' => max(0, $highestDataRow - 4),
            'processed_rows' => 0,
        ]);

        for ($chunkStart = 5; $chunkStart <= $highestDataRow; $chunkStart += 500) {
            $chunkEnd = min($chunkStart + 499, $highestDataRow);
            $reader->setLoadSheetsOnly([$worksheetName]);
            $reader->setReadFilter(new HouseholdImportChunkReadFilter($chunkStart, $chunkEnd));
            $chunkWorkbook = $reader->load($data['file']->getRealPath());
            $sheet = $chunkWorkbook->getSheetByName($worksheetName);

            for ($row = $chunkStart; $row <= $chunkEnd; $row++) {
                if (($row - 4) % 500 === 0) {
                    $importLog->update(['processed_rows' => $row - 4]);
                }

                // map các trường vào cell
                $householdCode = $this->cell($sheet, 'O'.$row);
                $lastName = $this->cell($sheet, 'C'.$row);
                $firstName = $this->cell($sheet, 'D'.$row);
                // bỏ qua các dòng trống k có dữ liệu
                if ($householdCode === '' && $lastName === '' && $firstName === '') {
                    continue;
                }

                if ($householdCode === '' || $lastName === '' || $firstName === '') {
                    $errors[] = ['row' => $row, 'message' => 'Cần có Số phiếu, Họ đệm và Tên.'];

                    continue;
                }

                $ethnicityName = $this->cell($sheet, 'I'.$row);
                // nếu trống để code là null còn cps trog mảng kia thì trả ra cái code để lưu code dân tộc
                $ethnicityCode = $ethnicityName === '' ? null : ($ethnicityCodes[$this->normalize($ethnicityName)] ?? null);
                if ($ethnicityName === '') {
                    $errors[] = ['row' => $row, 'message' => 'Cần có dân tộc.'];
                } elseif ($ethnicityCode === null) {
                    $errors[] = ['row' => $row, 'message' => "Dân tộc '{$ethnicityName}' chưa có trong danh mục."];
                }

                $birthDate = $this->birthDate($sheet, $row);

                if ($birthDate['date'] === null) {
                    $errors[] = ['row' => $row, 'message' => 'Cần có ngày, tháng và năm sinh hợp lệ.'];
                } elseif ($birthDate['date'] > now()->toDateString()) {
                    $errors[] = ['row' => $row, 'message' => 'Ngày sinh không được ở tương lai.'];
                }
                //
                $schoolCode = $this->cell($sheet, 'V'.$row);
                if ($schoolCode !== '') {
                    if ($schoolCommuneCodes->has($schoolCode)) {
                        if ($schoolCommuneCodes->get($schoolCode) !== $data['commune_code']) {
                            $errors[] = ['row' => $row, 'message' => "Mã trường '{$schoolCode}' không thuộc xã được chọn."];
                        }
                    } else {
                        $errors[] = ['row' => $row, 'message' => "Mã trường '{$schoolCode}' không tồn tại trong danh mục trường học."];
                    }
                }

                $record = [
                    'row' => $row,
                    'household_code' => $householdCode,
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'dob' => $birthDate,
                    // tick là nữ ko nam
                    'gender' => $this->isMarked($this->cell($sheet, 'H'.$row)) ? 'NU' : 'NAM',
                    'ethnicity_code' => $ethnicityCode,

                    'religion' => $this->nullableCell($sheet, 'J'.$row),
                    'priority_type' => $this->nullableCell($sheet, 'K'.$row),
                    'head_last_name' => $this->cell($sheet, 'L'.$row),
                    'head_first_name' => $this->cell($sheet, 'M'.$row),
                    'address' => $this->nullableCell($sheet, 'N'.$row),
                    'residence_type' => $this->cell($sheet, 'P'.$row),
                    'residence_status' => $this->nullableCell($sheet, 'Q'.$row),
                    'academic_block' => $this->nullableCell($sheet, 'R'.$row),
                    'current_class' => $this->nullableCell($sheet, 'S'.$row),
                    'school_code' => $schoolCode ?: null,
                    'graduation_level' => $this->nullableCell($sheet, 'W'.$row),
                    'is_complementary' => $this->isMarked($this->cell($sheet, 'X'.$row)),
                    'graduation_year' => $this->nullableCell($sheet, 'Y'.$row),
                    'vocational_grad_level' => $this->nullableCell($sheet, 'Z'.$row),
                    'vocational_grad_year' => $this->nullableCell($sheet, 'AB'.$row),
                    'finished_class' => $this->nullableCell($sheet, 'AC'.$row),
                    'finished_year' => $this->nullableCell($sheet, 'AD'.$row),
                    'dropped_class' => $this->nullableCell($sheet, 'AE'.$row),
                    'dropped_year' => $this->nullableCell($sheet, 'AF'.$row),
                    'literacy_current_class' => $this->nullableCell($sheet, 'AG'.$row),
                    'literacy_completed_class' => $this->nullableCell($sheet, 'AH'.$row),
                    'literacy_relapse_level' => $this->nullableCell($sheet, 'AI'.$row),
                    'disability' => [
                        'mobility_disability' => $this->isMarked($this->cell($sheet, 'AJ'.$row)),
                        'hearing_speech_disability' => $this->isMarked($this->cell($sheet, 'AK'.$row)),
                        'visual_disability' => $this->isMarked($this->cell($sheet, 'AL'.$row)),
                        'mental_disability' => $this->isMarked($this->cell($sheet, 'AM'.$row)),
                        'intellectual_disability' => $this->isMarked($this->cell($sheet, 'AN'.$row)),
                        'learning_disability' => $this->isMarked($this->cell($sheet, 'AO'.$row)),
                        'autism' => $this->isMarked($this->cell($sheet, 'AP'.$row)),
                        'other_disability' => $this->isMarked($this->cell($sheet, 'AQ'.$row)),
                        'has_disability_cert' => $this->isMarked($this->cell($sheet, 'AR'.$row)),
                        'can_study' => $this->isMarked($this->cell($sheet, 'AS'.$row)),
                        'special_circumstance' => $this->nullableCell($sheet, 'AT'.$row),
                        'special_circumstance_detail' => $this->nullableCell($sheet, 'AU'.$row),
                    ],
                    'relationship_with_head' => $this->nullableCell($sheet, 'AV'.$row),
                    'parent_name' => $this->nullableCell($sheet, 'AW'.$row),
                    'phone' => $this->nullableCell($sheet, 'AX'.$row),
                    'note' => $this->nullableCell($sheet, 'AY'.$row),
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
                        $errors[] = ['row' => $row, 'message' => "{$label} không được lớn hơn ".now()->year.'.'];
                    }
                }

                // lấy value của key householdCode trong mảng groups
                $recordsByCode = $groups[$householdCode] ?? [];
                // push thêm nhân khẩu vào
                $recordsByCode[] = $record;
                // value của mảng mà key đó sẽ bằng mảng mới
                $groups[$householdCode] = $recordsByCode;
                $totalRows++;
            }
            $chunkWorkbook->disconnectWorksheets();
        }
        $importLog->update(['processed_rows' => max(0, $highestDataRow - 4)]);

        if ($totalRows === 0) {
            $errors[] = ['row' => null, 'message' => 'Không tìm thấy dòng dữ liệu. Dữ liệu cần bắt đầu từ dòng 5.'];
        }

        if ($errors !== []) {
            $this->recordFailedImport($importLog, $data['file']->getRealPath(), $worksheetName, $totalRows, $errors);

            return response()->json(['message' => implode(' ', array_map(
                static fn (array $error): string => $error['message'],
                array_slice($errors, 0, 10)
            ))], 422);
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
                foreach ($headRows as $headRow) {
                    $errors[] = [
                        'row' => $headRow,
                        'message' => "Phiếu '{$householdCode}' có nhiều chủ hộ ở dòng ".implode(', ', $headRows).'.',
                    ];
                }
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
                        $errors[] = ['row' => $record['row'], 'message' => $ageError];
                    }
                }
            }
        }

        if ($errors !== []) {
            $this->recordFailedImport($importLog, $data['file']->getRealPath(), $worksheetName, $totalRows, $errors);

            return response()->json(['message' => implode(' ', array_map(
                static fn (array $error): string => $error['message'],
                array_slice($errors, 0, 10)
            ))], 422);
        }

        // check query có tồn tại cái mã hộ này k
        $existingCode = Household::query()
            ->where('school_year', $schoolYear)
            ->whereIn('household_code', array_keys($groups))
            ->value('household_code');
        if ($existingCode !== null) {
            $duplicateErrors = array_map(
                static fn (array $record): array => [
                    'row' => $record['row'],
                    'message' => "Mã phiếu '{$existingCode}' đã tồn tại. Không nhập file để tránh ghi đè hoặc nhân đôi dữ liệu.",
                ],
                $groups[$existingCode] ?? []
            );
            $this->recordFailedImport(
                $importLog,
                $data['file']->getRealPath(),
                $worksheetName,
                $totalRows,
                $duplicateErrors ?: [['row' => null, 'message' => "Mã phiếu '{$existingCode}' đã tồn tại. Không nhập file để tránh ghi đè hoặc nhân đôi dữ liệu."]]
            );

            return response()->json([
                'message' => "Mã phiếu '{$existingCode}' đã tồn tại. Không nhập file để tránh ghi đè hoặc nhân đôi dữ liệu.",
            ], 422);
        }

        $importLog->update([
            'total_rows' => $totalRows,
            'status' => 'PROCESSING',
        ]);

        try {
            // dùng transaction để rollback nếu có err
            DB::transaction(function () use ($groups, $data, $schoolYear, $importLog, &$totalRows) {
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
                    if (! $hasHeadPerson && ($headLastName !== '' || $headFirstName !== '')) {
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
            $this->recordFailedImport(
                $importLog,
                $data['file']->getRealPath(),
                $worksheetName,
                $totalRows,
                [['row' => null, 'message' => $exception->getMessage()]]
            );

            return response()->json(['message' => 'Nhập dữ liệu thất bại: '.$exception->getMessage()], 422);
        }

        // update status ở bảng import_logs
        $importLog->update([
            'status' => 'SUCCESS',
            'success_rows' => $totalRows,
        ]);

        $destination = $user->can('view-households')
            ? route('households.index', [], false)
            : route('index', [], false);

        return response()->json([
            'success' => true,
            'destination' => $destination,
            'message' => "Đã nhập {$totalRows} dòng nhân khẩu từ file Excel.",
        ]);
    }

    private function cell(Worksheet $sheet, string $coordinate): string
    {
        // tìm tới vị trí $coordinate lấy value
        return trim((string) $sheet->getCell($coordinate)->getFormattedValue());
    }

    private function recordFailedImport(
        ImportLog $importLog,
        string $filePath,
        string $worksheetName,
        int $totalRows,
        array $errors,
    ): void {
        $errorsByRow = [];
        foreach ($errors as $error) {
            $row = $error['row'] ?? null;
            $cell = $row === null ? 'A1' : 'A'.$row;
            $errorsByRow[$cell][] = $error['message'];
        }

        $errorFilePath = null;
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getSheetByName($worksheetName);
            if ($sheet === null) {
                throw new \RuntimeException('Không tìm thấy trang tính dữ liệu để ghi chú lỗi.');
            }

            foreach ($errorsByRow as $cell => $messages) {
                $sheet->setCellValue($cell, implode(' | ', array_unique($messages)));
                $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFDC3545');
                $sheet->getStyle($cell)->getAlignment()->setWrapText(true);
            }
            $sheet->getColumnDimension('A')->setWidth(45);

            $writerType = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'xls' ? 'Xls' : 'Xlsx';
            $errorFilePath = dirname($importLog->file_path).'/error-'.basename($importLog->file_path);
            IOFactory::createWriter($spreadsheet, $writerType)
                ->save(Storage::disk('local')->path($errorFilePath));
            $spreadsheet->disconnectWorksheets();
        } catch (Throwable $exception) {
            $errorFilePath = null;
            Log::error('Không thể tạo file Excel có ghi chú lỗi khi nhập dữ liệu.', [
                'import_log_id' => $importLog->id,
                'exception' => $exception,
            ]);
        }

        $errorDetails = array_map(
            static fn (string $cell, array $messages): array => [
                'cell' => $cell,
                'row' => (int) substr($cell, 1),
                'message' => implode(' | ', array_unique($messages)),
            ],
            array_keys($errorsByRow),
            array_values($errorsByRow)
        );

        $importLog->update([
            'status' => 'FAILED',
            'total_rows' => $totalRows,
            'error_rows' => count($errorsByRow),
            'error_details' => array_slice($errorDetails, 0, 200),
            'error_file_path' => $errorFilePath,
        ]);
    }

    // trả về string hoặc null
    private function nullableCell(Worksheet $sheet, string $coordinate): ?string
    {
        // trả ra value cell đó hoặc null
        return $this->cell($sheet, $coordinate) ?: null;
    }

    private function birthDate(Worksheet $sheet, int $row): array
    {
        $day = $this->cell($sheet, 'E'.$row);
        $month = $this->cell($sheet, 'F'.$row);
        $year = $this->cell($sheet, 'G'.$row);
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
            if (! empty($record[$key])) {
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
