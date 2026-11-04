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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
                    ...$person->only([
                        'id',
                        'first_name',
                        'last_name',
                        'gender',
                        'ethnicity_code',
                        'religion',
                        'priority_type',
                        'relationship_with_head',
                        'parent_name',
                        'phone',
                        'note',
                    ]),
                    'name' => trim($person->last_name.' '.$person->first_name),
                    'dob' => $person->dob?->format('Y-m-d'),
                    'ethnicity' => $person->ethnicity?->only(['code', 'name']),
                    'educations' => $person->educations->map(fn ($education): array => [
                        ...$education->only([
                            'id',
                            'school_year',
                            'academic_block',
                            'current_class',
                            'school_code',
                            'graduation_level',
                            'is_complementary',
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
                            'learning_capacity',
                        ]),
                        'school' => $education->school?->only(['code', 'name']),
                    ])->values(),
                    'disability' => $person->disability?->only([
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
                        'special_circumstance',
                        'special_circumstance_detail',
                    ]),
                ])->values(),
            ],
        ]);
    }
}
