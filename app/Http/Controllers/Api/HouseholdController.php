<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\User;
use App\Services\Households\HouseholdService;
use App\Support\DataAccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HouseholdController extends Controller
{
    public function index(Request $request, HouseholdService $households): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'province_code' => ['nullable', 'string', 'max:50'],
            'commune_code' => ['nullable', 'string', 'max:50'],
            'village_code' => ['nullable', 'string', 'max:50'],
            'school_year' => ['nullable', 'string', 'max:20'],
            'school_code' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        foreach ($households->defaultFilters($user) as $key => $value) {
            if (! array_key_exists($key, $filters) || blank($filters[$key])) {
                $filters[$key] = $value;
            }
        }

        $results = $households->paginate($user, $filters, withPersonDetails: false);
        $results->through(fn (Household $household): array => [
            'id' => $household->id,
            'household_code' => $household->household_code,
            'school_year' => $household->school_year,
            'head_name' => trim($household->head_last_name.' '.$household->head_first_name),
            'province_name' => $household->province?->name,
            'commune_name' => $household->commune?->name,
            'village_name' => $household->village?->name,
            'members_count' => $household->persons_count + ($household->head_persons_count === 0 ? 1 : 0),
        ]);

        return response()->json($results);
    }

    public function show(Household $household, Request $request, HouseholdService $households): JsonResponse
    {
        abort_unless(DataAccessScope::canAccessHousehold($request->user(), $household), 404);
        ['persons' => $persons, 'headPerson' => $headPerson, 'hasAnyHouseholdHead' => $hasAnyHouseholdHead] =
            $households->details($household);

        return response()->json([
            'data' => [
                'id' => $household->id,
                'household_code' => $household->household_code,
                'school_year' => $household->school_year,
                'head_first_name' => $household->head_first_name,
                'head_last_name' => $household->head_last_name,
                'province' => $household->province?->only(['code', 'name']),
                'commune' => $household->commune?->only(['code', 'name']),
                'village' => $household->village?->only(['code', 'name']),
                'address' => $household->address,
                'residence_type' => $household->residence_type,
                'residence_status' => $household->residence_status,
                'has_household_head' => $hasAnyHouseholdHead,
                'head' => $headPerson?->only([
                    'id',
                    'first_name',
                    'last_name',
                    'dob',
                    'gender',
                    'relationship_with_head',
                ]),
                'persons' => $persons->map(fn ($person): array => [
                    'id' => $person->id,
                    'name' => trim($person->last_name.' '.$person->first_name),
                    'dob' => $person->dob?->format('Y-m-d'),
                    'gender' => $person->gender,
                    'relationship_with_head' => $person->relationship_with_head,
                ])->values(),
            ],
        ]);
    }
}
