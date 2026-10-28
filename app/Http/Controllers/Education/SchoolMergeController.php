<?php

namespace App\Http\Controllers\Education;

use App\Http\Controllers\Controller;
use App\Http\Requests\Education\StoreSchoolMergeRequest;
use App\Models\Role;
use App\Services\Education\SchoolMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolMergeController extends Controller
{
    public function __construct(private readonly SchoolMergeService $schoolMerges) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasRole(Role::SYSTEM_ADMIN)
                || in_array($request->user()->roleScopeLevel(), ['province', 'commune'], true),
            403
        );

        return response()->json(['data' => $this->schoolMerges->availableForMerge($request->user())]);
    }

    public function store(StoreSchoolMergeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $count = $this->schoolMerges->merge(
            $request->user(),
            $data['source_truong_ids'],
            $data['target_truong_id'],
            $data['qd_sap_nhap'] ?? null,
        );

        return response()->json([
            'message' => sprintf('Đã gộp trường và chuyển %s hồ sơ giáo dục.', number_format($count, 0, ',', '.')),
        ]);
    }
}
