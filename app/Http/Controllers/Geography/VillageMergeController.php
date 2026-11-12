<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Http\Requests\Geography\MergeVillagesRequest;
use App\Services\Geography\VillageService;
use Illuminate\Http\Request;

class VillageMergeController extends Controller
{
    public function __construct(private readonly VillageService $villages) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->roleScopeLevel() === 'commune' && filled($user->commune_code), 403);

        $villages = $this->villages->availableForMerge($user);

        return response()->json(['data' => $villages]);
    }

    public function store(MergeVillagesRequest $request)
    {
        $data = $request->validated();
        $householdCount = $this->villages->merge(
            $request->user(),
            $data['source_codes'],
            $data['target_code']
        );

        return response()->json([
            'message' => sprintf(
                'Đã gộp %d thôn nguồn vào thôn đích, xóa thôn nguồn và chuyển %s hộ cùng toàn bộ nhân khẩu.',
                count($data['source_codes']),
                number_format($householdCount, 0, ',', '.')
            ),
        ]);
    }

    public function pause(Request $request)
    {
        $data = $request->validate([
            'source_codes' => ['required', 'array', 'min:1'],
            'source_codes.*' => ['required', 'string', 'max:20', 'distinct'],
        ]);
        $pausedCount = $this->villages->pauseIntake(
            $request->user(),
            $data['source_codes'],
        );

        return response()->json([
            'message' => "Đã tạm dừng nhận phiếu tại {$pausedCount} thôn nguồn.",
        ]);
    }

    public function resume(Request $request)
    {
        $data = $request->validate([
            'source_codes' => ['required', 'array', 'min:1'],
            'source_codes.*' => ['required', 'string', 'max:20', 'distinct'],
        ]);
        $resumedCount = $this->villages->resumeIntake(
            $request->user(),
            $data['source_codes'],
        );

        return response()->json([
            'message' => "Đã mở lại nhận phiếu tại {$resumedCount} thôn.",
        ]);
    }
}
