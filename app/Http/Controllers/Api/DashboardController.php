<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\SystemOverviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function overview(Request $request, SystemOverviewService $overview): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasRole(Role::SYSTEM_ADMIN), 403);

        $filters = $request->validate([
            'school_year' => [
                'nullable',
                'string',
                Rule::in($overview->schoolYears()),
            ],
        ]);

        return ApiResponse::success($overview->summary($filters['school_year'] ?? null));
    }
}
