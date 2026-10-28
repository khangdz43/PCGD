<?php

namespace App\Http\Controllers\Education;

use App\Http\Controllers\Controller;
use App\Models\Commune;
use App\Models\Role;
use App\Models\SchoolMergeHistory;
use App\Models\User;
use App\Models\VillageMergeHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class MergeHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(
            $user->hasRole(Role::SYSTEM_ADMIN)
                || $user->can('manage-schools')
                || $user->can('manage-villages'),
            403
        );

        $histories = collect()
            ->concat($this->villageHistories($user))
            ->concat($this->schoolHistories($user))
            ->sortByDesc('created_at')
            ->values();
        $perPage = 20;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = $histories->forPage($page, $perPage)->values();

        return response()->json([
            'merge_histories' => new LengthAwarePaginator(
                $items,
                $histories->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            ),
        ]);
    }

    private function villageHistories(User $user): Collection
    {
        $query = VillageMergeHistory::query()->with('mergedBy:id,name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            if ($user->roleScopeLevel() === 'commune') {
                $query->where('commune_code', $user->commune_code);
            } elseif ($user->roleScopeLevel() === 'province') {
                $communeCodes = Commune::query()
                    ->where('province_code', $user->province_code)
                    ->select('code');
                $query->whereIn('commune_code', $communeCodes);
            } else {
                return collect();
            }
        }

        return $query->get()->map(fn (VillageMergeHistory $history): array => [
            'id' => 'village-'.$history->id,
            'type' => 'village',
            'source_names' => $history->source_names,
            'source_codes' => $history->source_codes,
            'target_name' => $history->target_name,
            'target_code' => $history->target_code,
            'reason' => $history->reason,
            'merged_by' => $history->mergedBy?->name,
            'created_at' => $history->created_at,
        ]);
    }

    private function schoolHistories(User $user): Collection
    {
        $query = SchoolMergeHistory::query()->with('mergedBy:id,name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $query->where('province_code', $user->province_code);
            if ($user->roleScopeLevel() === 'commune') {
                $query->where('commune_code', $user->commune_code);
            } elseif ($user->roleScopeLevel() !== 'province') {
                return collect();
            }
        }

        return $query->get()->map(fn (SchoolMergeHistory $history): array => [
            'id' => 'school-'.$history->id,
            'type' => 'school',
            'source_names' => $history->source_names,
            'source_codes' => $history->source_codes,
            'target_name' => $history->target_name,
            'target_code' => $history->target_code,
            'reason' => $history->qd_sap_nhap,
            'merged_by' => $history->mergedBy?->name,
            'created_at' => $history->created_at,
        ]);
    }
}
