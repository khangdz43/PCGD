<?php

namespace Database\Seeders;

use App\Models\Commune;
use App\Models\Household;
use App\Models\Permission;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Models\Village;
use App\Support\AccessPermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AccessControlTestAccountsSeeder extends Seeder
{
    private const ACCOUNTS = [
        'view' => [
            'label' => 'Chỉ xem phiếu',
            'permissions' => [AccessPermission::VIEW_HOUSEHOLDS],
            'scope' => 'village',
        ],
        'create' => [
            'label' => 'Chỉ tạo phiếu',
            'permissions' => [AccessPermission::CREATE_HOUSEHOLDS],
            'scope' => 'village',
        ],
        'update' => [
            'label' => 'Chỉ sửa phiếu',
            'permissions' => [AccessPermission::UPDATE_HOUSEHOLDS],
            'scope' => 'village',
        ],
        'delete' => [
            'label' => 'Chỉ xóa phiếu',
            'permissions' => [AccessPermission::DELETE_HOUSEHOLDS],
            'scope' => 'village',
        ],
        'import' => [
            'label' => 'Chỉ nhập Excel',
            'permissions' => [AccessPermission::IMPORT_HOUSEHOLDS],
            'scope' => 'village',
        ],
        'export' => [
            'label' => 'Chỉ xuất Excel',
            'permissions' => [AccessPermission::EXPORT_HOUSEHOLDS],
            'scope' => 'village',
        ],
        'form-entry' => [
            'label' => 'Nhập liệu bằng biểu mẫu',
            'permissions' => [
                AccessPermission::CREATE_HOUSEHOLDS,
                AccessPermission::UPDATE_HOUSEHOLDS,
            ],
            'scope' => 'village',
        ],
        'excel-and-form' => [
            'label' => 'Nhập Excel và biểu mẫu',
            'permissions' => [
                AccessPermission::CREATE_HOUSEHOLDS,
                AccessPermission::UPDATE_HOUSEHOLDS,
                AccessPermission::IMPORT_HOUSEHOLDS,
                AccessPermission::EXPORT_HOUSEHOLDS,
            ],
            'scope' => 'village',
        ],
        'manage-villages' => [
            'label' => 'Quản lý địa bàn',
            'permissions' => [AccessPermission::MANAGE_VILLAGES],
            'scope' => 'commune',
        ],
        'manage-schools' => [
            'label' => 'Quản lý trường học',
            'permissions' => [AccessPermission::MANAGE_SCHOOLS],
            'scope' => 'province',
        ],
        'manage-users' => [
            'label' => 'Quản lý tài khoản',
            'permissions' => [AccessPermission::MANAGE_USER_ACCOUNTS],
            'scope' => 'province',
        ],
        'manage-groups' => [
            'label' => 'Quản lý nhóm người dùng',
            'permissions' => [AccessPermission::MANAGE_ACCESS_CONTROL],
            'scope' => 'province',
        ],
        'commune-admin' => [
            'label' => 'Quản trị tổng hợp cấp xã',
            'permissions' => [
                AccessPermission::MANAGE_USER_ACCOUNTS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
                AccessPermission::MANAGE_VILLAGES,
                AccessPermission::MANAGE_SCHOOLS,
                AccessPermission::VIEW_HOUSEHOLDS,
                AccessPermission::CREATE_HOUSEHOLDS,
                AccessPermission::UPDATE_HOUSEHOLDS,
                AccessPermission::DELETE_HOUSEHOLDS,
                AccessPermission::IMPORT_HOUSEHOLDS,
                AccessPermission::EXPORT_HOUSEHOLDS,
            ],
            'scope' => 'commune',
        ],
        'province-viewer' => [
            'label' => 'Chỉ xem dữ liệu cấp tỉnh',
            'permissions' => [AccessPermission::VIEW_HOUSEHOLDS],
            'scope' => 'province',
        ],
    ];

    public function run(): void
    {
        $plainPassword = getenv('PCGD_TEST_ACCOUNT_PASSWORD');
        if (! is_string($plainPassword) || mb_strlen($plainPassword) < 12) {
            throw new RuntimeException('Hãy đặt PCGD_TEST_ACCOUNT_PASSWORD thành mật khẩu test dài ít nhất 12 ký tự trước khi chạy seeder.');
        }

        $household = Household::query()
            ->whereNotNull('province_code')
            ->whereNotNull('commune_code')
            ->whereNotNull('village_code')
            ->orderBy('household_code')
            ->first();
        $province = $household === null
            ? null
            : Province::where('code', $household->province_code)->first();
        $commune = $household === null
            ? null
            : Commune::where('code', $household->commune_code)->first();
        $village = $household === null
            ? null
            : Village::where('code', $household->village_code)->first();

        if ($province === null || $commune === null || $village === null) {
            throw new RuntimeException('Cần có ít nhất một tỉnh, xã và thôn trong danh mục trước khi tạo tài khoản test.');
        }

        $permissions = collect(AccessPermission::definitions())
            ->map(fn (string $description, string $name): Permission => Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $description]
            ));
        $passwordHash = Hash::make($plainPassword);

        DB::transaction(function () use ($permissions, $passwordHash, $province, $commune, $village): void {
            foreach (self::ACCOUNTS as $key => $definition) {
                $roleName = 'test-'.$key;
                $role = Role::updateOrCreate(
                    ['name' => $roleName, 'guard_name' => 'web'],
                    [
                        'scope_level' => 'global',
                        'description' => 'TÀI KHOẢN TEST — '.$definition['label'],
                        'created_by' => null,
                        'province_code' => null,
                        'commune_code' => null,
                    ]
                );

                $permissionNames = AccessPermission::withPrerequisites($definition['permissions']);
                $role->permissions()->sync(
                    $permissions->whereIn('name', $permissionNames)->pluck('id')->all()
                );

                $user = User::firstOrNew(['username' => "pcgd-test-{$key}"]);
                $user->name = '[TEST] '.$definition['label'];
                $user->password = $passwordHash;
                $user->is_active = true;
                $user->province_code = $province->code;
                $user->commune_code = in_array($definition['scope'], ['commune', 'village'], true)
                    ? $commune->code
                    : null;
                $user->scope_level = $definition['scope'];
                $user->save();
                $user->roles()->sync([$role->id => ['assigned_by' => null]]);
                $user->villages()->sync($definition['scope'] === 'village' ? [$village->code] : []);
            }
        });

        $this->command?->info('Đã tạo/cập nhật '.count(self::ACCOUNTS).' tài khoản test (username pcgd-test-*).');
        $this->command?->info("Phạm vi test: {$province->name} / {$commune->name} / {$village->name}.");
        $this->command?->warn('Mật khẩu không được ghi vào source code. Dùng giá trị PCGD_TEST_ACCOUNT_PASSWORD đã nhập khi chạy lệnh.');
    }
}
