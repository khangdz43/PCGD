<?php

namespace App\Support;

final class AccessPermission
{
    public const VIEW_HOUSEHOLDS = 'view-households';

    public const CREATE_HOUSEHOLDS = 'create-households';

    public const UPDATE_HOUSEHOLDS = 'update-households';

    public const DELETE_HOUSEHOLDS = 'delete-households';

    public const IMPORT_HOUSEHOLDS = 'import-households';

    public const EXPORT_HOUSEHOLDS = 'export-households';

    public const MANAGE_CATALOGS = 'manage-catalogs';

    public const MANAGE_VILLAGES = 'manage-villages';

    public const MANAGE_SCHOOLS = 'manage-schools';

    public const MANAGE_ACCESS_CONTROL = 'manage-access-control';

    public const MANAGE_USER_ACCOUNTS = 'manage-user-accounts';

    public const VIEW_ORGANIZATION_ACCOUNTS = 'view-organization-accounts';

    // để mapping từ permission key sang nhãn hiển thị
    public static function definitions(): array
    {
        return [
            self::VIEW_HOUSEHOLDS => 'Xem phiếu điều tra và nhân khẩu',
            self::CREATE_HOUSEHOLDS => 'Tạo phiếu điều tra và nhân khẩu',
            self::UPDATE_HOUSEHOLDS => 'Cập nhật phiếu điều tra và nhân khẩu',
            self::DELETE_HOUSEHOLDS => 'Xóa phiếu điều tra và nhân khẩu',
            self::IMPORT_HOUSEHOLDS => 'Nhập dữ liệu phiếu điều tra',
            self::EXPORT_HOUSEHOLDS => 'Xuất dữ liệu phiếu điều tra',
            self::MANAGE_VILLAGES => 'Quản lý danh mục địa bàn (thôn / xóm)',
            self::MANAGE_SCHOOLS => 'Quản lý danh mục trường học (chỉ cấp tỉnh)',
            self::MANAGE_ACCESS_CONTROL => 'Tạo và chỉnh sửa nhóm quyền trong phạm vi quản lý',
            self::MANAGE_USER_ACCOUNTS => 'Tạo tài khoản và gán nhóm quyền trong phạm vi quản lý',
            self::VIEW_ORGANIZATION_ACCOUNTS => 'Xem cây đơn vị và thống kê tài khoản trong quản lý người dùng',
        ];
    }

    public static function permissionsForScope(string $scopeLevel): array
    {
        return match ($scopeLevel) {
            'province' => [
                self::MANAGE_SCHOOLS,
                self::MANAGE_ACCESS_CONTROL,
                self::MANAGE_USER_ACCOUNTS,
            ],
            'commune' => [
                self::VIEW_HOUSEHOLDS,
                self::CREATE_HOUSEHOLDS,
                self::UPDATE_HOUSEHOLDS,
                self::DELETE_HOUSEHOLDS,
                self::IMPORT_HOUSEHOLDS,
                self::EXPORT_HOUSEHOLDS,
                self::MANAGE_VILLAGES,
                self::MANAGE_ACCESS_CONTROL,
                self::MANAGE_USER_ACCOUNTS,
            ],
            'village' => [
                self::VIEW_HOUSEHOLDS,
                self::CREATE_HOUSEHOLDS,
                self::UPDATE_HOUSEHOLDS,
                self::DELETE_HOUSEHOLDS,
                self::IMPORT_HOUSEHOLDS,
                self::EXPORT_HOUSEHOLDS,
            ],
            default => [],
        };
    }

    public static function householdPermissions(): array
    {
        return [
            self::VIEW_HOUSEHOLDS,
            self::CREATE_HOUSEHOLDS,
            self::UPDATE_HOUSEHOLDS,
            self::DELETE_HOUSEHOLDS,
            self::IMPORT_HOUSEHOLDS,
            self::EXPORT_HOUSEHOLDS,
        ];
    }

    public static function prerequisites(): array
    {
        return [
            self::CREATE_HOUSEHOLDS => [self::VIEW_HOUSEHOLDS],
            self::UPDATE_HOUSEHOLDS => [self::VIEW_HOUSEHOLDS],
            self::DELETE_HOUSEHOLDS => [self::VIEW_HOUSEHOLDS],
            self::IMPORT_HOUSEHOLDS => [self::VIEW_HOUSEHOLDS],
            self::EXPORT_HOUSEHOLDS => [self::VIEW_HOUSEHOLDS],
        ];
    }

    public static function impliedPermissions(string $permission): array
    {
        $implied = [];
        foreach (self::prerequisites() as $dependent => $prerequisites) {
            if (in_array($permission, $prerequisites, true)) {
                $implied[] = $dependent;
            }
        }

        return $implied;
    }

    public static function withPrerequisites(array $permissions): array
    {
        $resolved = array_values(array_unique($permissions));
        foreach ($resolved as $permission) {
            foreach (self::prerequisites()[$permission] ?? [] as $prerequisite) {
                if (! in_array($prerequisite, $resolved, true)) {
                    $resolved[] = $prerequisite;
                }
            }
        }

        return $resolved;
    }
}
