<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commune;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Models\Village;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationAccountController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->hasRole(Role::SYSTEM_ADMIN), 403);

        $selection = $request->validate([
            'unit_type' => ['nullable', 'string', Rule::in(['province', 'commune', 'village'])],
            'unit_code' => ['nullable', 'string', 'max:20'],
        ]);

        abort_if(
            array_key_exists('unit_type', $selection) !== array_key_exists('unit_code', $selection),
            404
        );

        $provinces = Province::query()
            ->with([
                'communes' => fn ($query) => $query->orderBy('name'),
                'communes.villages' => fn ($query) => $query->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        $selectedType = $selection['unit_type'] ?? ($provinces->isNotEmpty() ? 'province' : null);
        $selectedCode = $selection['unit_code'] ?? $provinces->first()?->code;
        $selectedUnit = null;

        if ($selectedType !== null) {
            $selectedUnit = match ($selectedType) {
                'province' => $provinces->firstWhere('code', $selectedCode),
                'commune' => Commune::query()->with('province')->find($selectedCode),
                'village' => Village::query()->with('commune.province')->find($selectedCode),
            };
            abort_if($selectedUnit === null, 404);
        }

        $accounts = fn () => User::query()
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('roles.name', Role::SYSTEM_ADMIN));

        $locatedAccounts = fn () => $accounts()->where(function (Builder $query): void {
            $query->whereNotNull('province_code')
                ->orWhereNotNull('commune_code')
                ->orWhereHas('villages');
        });

        $provinceCounts = (clone $locatedAccounts())
            ->select('province_code')
            ->selectRaw('COUNT(*) AS account_count')
            ->whereNotNull('province_code')
            ->groupBy('province_code')
            ->pluck('account_count', 'province_code');

        $communeCounts = (clone $locatedAccounts())
            ->select('commune_code')
            ->selectRaw('COUNT(*) AS account_count')
            ->whereNotNull('commune_code')
            ->groupBy('commune_code')
            ->pluck('account_count', 'commune_code');

        $villageCounts = [];
        $villageAccounts = $locatedAccounts()
            ->whereHas('villages')
            ->with('villages:code')
            ->get(['users.id']);
        foreach ($villageAccounts as $account) {
            foreach ($account->villages as $village) {
                $villageCounts[$village->code] = ($villageCounts[$village->code] ?? 0) + 1;
            }
        }

        $unitAccounts = $accounts();
        if ($selectedType === 'province') {
            $unitAccounts->where('province_code', $selectedCode);
        } elseif ($selectedType === 'commune') {
            $unitAccounts->where('commune_code', $selectedCode);
        } elseif ($selectedType === 'village') {
            $unitAccounts->whereHas('villages', fn (Builder $villages) => $villages->where('villages.code', $selectedCode));
        } else {
            $unitAccounts->whereRaw('1 = 0');
        }

        $totalAccounts = $locatedAccounts()->count();
        $activeAccounts = (clone $locatedAccounts())->where('is_active', true)->count();
        $inactiveAccounts = $totalAccounts - $activeAccounts;
        $unitTotalAccounts = (clone $unitAccounts)->count();
        $unitActiveAccounts = (clone $unitAccounts)->where('is_active', true)->count();

        $unitAccounts = $unitAccounts
            ->with('roles:id,name')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $unitName = match (true) {
            $selectedUnit instanceof Province => $selectedUnit->name,
            $selectedUnit instanceof Commune => $selectedUnit->name.' / '.$selectedUnit->province?->name,
            $selectedUnit instanceof Village => $selectedUnit->name.' / '.$selectedUnit->commune?->name.' / '.$selectedUnit->commune?->province?->name,
            default => 'Chưa có địa bàn',
        };

        return view('admin.organization-accounts.index', compact(
            'provinces',
            'provinceCounts',
            'communeCounts',
            'villageCounts',
            'selectedType',
            'selectedCode',
            'selectedUnit',
            'unitName',
            'unitAccounts',
            'totalAccounts',
            'activeAccounts',
            'inactiveAccounts',
            'unitTotalAccounts',
            'unitActiveAccounts',
        ));
    }
}
