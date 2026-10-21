<?php

namespace App\Http\Controllers\Geography;

use App\Http\Controllers\Controller;
use App\Http\Requests\Geography\MergeVillagesRequest;
use App\Services\Geography\VillageService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class VillageMergeController extends Controller
{
    public function __construct(private readonly VillageService $villages) {}

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user->roleScopeLevel() === 'commune' && filled($user->commune_code), 403);

        $villages = $this->villages->availableForMerge($user);

        return view('utilities.merge-villages', compact('villages'));
    }

    public function store(MergeVillagesRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $householdCount = $this->villages->merge(
            $request->user(),
            $data['source_codes'],
            $data['target_code']
        );

        return to_route('utilities.villages.merge')->with(
            'success',
            sprintf(
                'Đã gộp %d thôn nguồn vào thôn đích và chuyển %s hộ cùng toàn bộ nhân khẩu.',
                count($data['source_codes']),
                number_format($householdCount, 0, ',', '.')
            )
        );
    }
}
