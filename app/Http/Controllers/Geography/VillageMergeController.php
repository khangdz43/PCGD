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
                'Đã gộp %d thôn nguồn vào thôn đích và chuyển %s hộ cùng toàn bộ nhân khẩu.',
                count($data['source_codes']),
                number_format($householdCount, 0, ',', '.')
            ),
        ]);
    }
}
