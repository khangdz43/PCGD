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


    public const MANAGE_VILLAGES = 'manage-villages';

    public const MANAGE_SCHOOLS = 'manage-schools';

    public const MANAGE_ACCESS_CONTROL = 'manage-access-control';

    public const MANAGE_USER_ACCOUNTS = 'manage-user-accounts';

    public const VIEW_ORGANIZATION_ACCOUNTS = 'view-organization-accounts';

    public const VIEW_USERS = 'view-users';

    public const CREATE_USERS = 'create-users';

    public const UPDATE_USERS = 'update-users';

    public const DELETE_USERS = 'delete-users';

    public const VIEW_ROLES = 'view-roles';

    public const CREATE_ROLES = 'create-roles';

    public const UPDATE_ROLES = 'update-roles';

    public const DELETE_ROLES = 'delete-roles';

    public static function systemManagementPermissions(): array
    {
        return [
            self::VIEW_ORGANIZATION_ACCOUNTS,
            self::MANAGE_USER_ACCOUNTS,
            self::VIEW_USERS,
            self::CREATE_USERS,
            self::UPDATE_USERS,
            self::DELETE_USERS,
            self::MANAGE_ACCESS_CONTROL,
            self::VIEW_ROLES,
            self::CREATE_ROLES,
            self::UPDATE_ROLES,
            self::DELETE_ROLES,
        ];
    }

    public const VIEW_SCHOOLS = 'view-schools';

    public const CREATE_SCHOOLS = 'create-schools';

    public const UPDATE_SCHOOLS = 'update-schools';

    public const DELETE_SCHOOLS = 'delete-schools';

    public const VIEW_SCHOOL_MERGES = 'view-school-merges';

    public const CREATE_SCHOOL_MERGES = 'create-school-merges';

    public const VIEW_VILLAGES = 'view-villages';

    public const CREATE_VILLAGES = 'create-villages';

    public const UPDATE_VILLAGES = 'update-villages';

    public const DELETE_VILLAGES = 'delete-villages';

    public const VIEW_VILLAGE_MERGES = 'view-village-merges';

    public const CREATE_VILLAGE_MERGES = 'create-village-merges';

    public static function mergePermissions(): array
    {
        return [
            self::VIEW_SCHOOL_MERGES,
            self::CREATE_SCHOOL_MERGES,
            self::VIEW_VILLAGE_MERGES,
            self::CREATE_VILLAGE_MERGES,
        ];
    }

    public const VIEW_IMPORTS = 'view-imports';

    public const CREATE_IMPORTS = 'create-imports';

    public const DELETE_IMPORTS = 'delete-imports';

    public const VIEW_EXPORTS = 'view-exports';

    public const CREATE_EXPORTS = 'create-exports';

    public const VIEW_TRASH = 'view-trash';

    public const UPDATE_TRASH = 'update-trash';

    public const DELETE_TRASH = 'delete-trash';

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
            self::MANAGE_SCHOOLS => 'Quản lý danh mục trường học',
            self::MANAGE_ACCESS_CONTROL => 'Quản lý nhóm người dùng',
            self::MANAGE_USER_ACCOUNTS => 'Tạo tài khoản và gán nhóm người dùng trong phạm vi quản lý',
            self::VIEW_ORGANIZATION_ACCOUNTS => 'Xem cây đơn vị và thống kê tài khoản trong quản lý người dùng',
            self::VIEW_USERS => 'Xem tài khoản',
            self::CREATE_USERS => 'Tạo tài khoản',
            self::UPDATE_USERS => 'Sửa tài khoản, đặt lại mật khẩu',
            self::DELETE_USERS => 'Thu hồi tài khoản',
            self::VIEW_ROLES => 'Xem nhóm người dùng',
            self::CREATE_ROLES => 'Tạo nhóm người dùng',
            self::UPDATE_ROLES => 'Sửa nhóm người dùng',
            self::DELETE_ROLES => 'Xóa nhóm người dùng',
            self::VIEW_SCHOOLS => 'Xem danh mục trường học',
            self::CREATE_SCHOOLS => 'Thêm trường học',
            self::UPDATE_SCHOOLS => 'Sửa trường học',
            self::DELETE_SCHOOLS => 'Xóa trường học',
            self::VIEW_SCHOOL_MERGES => 'Xem lịch sử gộp trường học',
            self::CREATE_SCHOOL_MERGES => 'Gộp trường học',
            self::VIEW_VILLAGES => 'Xem danh mục thôn / xóm',
            self::CREATE_VILLAGES => 'Thêm thôn / xóm',
            self::UPDATE_VILLAGES => 'Sửa thôn / xóm',
            self::DELETE_VILLAGES => 'Xóa thôn / xóm',
            self::VIEW_VILLAGE_MERGES => 'Xem lịch sử gộp thôn / xóm',
            self::CREATE_VILLAGE_MERGES => 'Gộp thôn / xóm',
            self::VIEW_IMPORTS => 'Xem lịch sử nhập dữ liệu',
            self::CREATE_IMPORTS => 'Nhập dữ liệu',
            self::DELETE_IMPORTS => 'Xóa lịch sử nhập dữ liệu',
            self::VIEW_EXPORTS => 'Xem và tải dữ liệu xuất',
            self::CREATE_EXPORTS => 'Xuất dữ liệu',
            self::VIEW_TRASH => 'Xem thùng rác',
            self::UPDATE_TRASH => 'Khôi phục dữ liệu',
            self::DELETE_TRASH => 'Xóa dữ liệu vĩnh viễn',
        ];
    }

    // các quyền theo phạm vi
    public static function permissionsForScope(string $scopeLevel): array
    {
        return match ($scopeLevel) {
            'global' => [
                self::VIEW_HOUSEHOLDS,
                self::CREATE_HOUSEHOLDS,
                self::UPDATE_HOUSEHOLDS,
                self::DELETE_HOUSEHOLDS,
                self::IMPORT_HOUSEHOLDS,
                self::EXPORT_HOUSEHOLDS,
                self::MANAGE_ACCESS_CONTROL,
                self::MANAGE_USER_ACCOUNTS,
                self::VIEW_ORGANIZATION_ACCOUNTS,
                self::VIEW_USERS,
                self::CREATE_USERS,
                self::UPDATE_USERS,
                self::DELETE_USERS,
                self::VIEW_ROLES,
                self::CREATE_ROLES,
                self::UPDATE_ROLES,
                self::DELETE_ROLES,
                self::VIEW_SCHOOLS,
                self::CREATE_SCHOOLS,
                self::UPDATE_SCHOOLS,
                self::DELETE_SCHOOLS,
                self::VIEW_VILLAGES,
                self::CREATE_VILLAGES,
                self::UPDATE_VILLAGES,
                self::DELETE_VILLAGES,
                self::VIEW_IMPORTS,
                self::CREATE_IMPORTS,
                self::DELETE_IMPORTS,
                self::VIEW_EXPORTS,
                self::CREATE_EXPORTS,
                self::VIEW_TRASH,
                self::UPDATE_TRASH,
                self::DELETE_TRASH,
            ],
            'province' => [
                self::MANAGE_SCHOOLS,
                self::MANAGE_ACCESS_CONTROL,
                self::MANAGE_USER_ACCOUNTS,
                self::VIEW_USERS,
                self::CREATE_USERS,
                self::UPDATE_USERS,
                self::DELETE_USERS,
                self::VIEW_ROLES,
                self::CREATE_ROLES,
                self::UPDATE_ROLES,
                self::DELETE_ROLES,
                self::VIEW_SCHOOLS,
                self::CREATE_SCHOOLS,
                self::UPDATE_SCHOOLS,
                self::DELETE_SCHOOLS,
                self::VIEW_SCHOOL_MERGES,
                self::CREATE_SCHOOL_MERGES,
            ],
            'commune' => [
                self::VIEW_HOUSEHOLDS,
                self::CREATE_HOUSEHOLDS,
                self::UPDATE_HOUSEHOLDS,
                self::DELETE_HOUSEHOLDS,
                self::IMPORT_HOUSEHOLDS,
                self::EXPORT_HOUSEHOLDS,
                self::MANAGE_VILLAGES,
                self::MANAGE_SCHOOLS,
                self::MANAGE_ACCESS_CONTROL,
                self::MANAGE_USER_ACCOUNTS,
                self::VIEW_USERS,
                self::CREATE_USERS,
                self::UPDATE_USERS,
                self::DELETE_USERS,
                self::VIEW_ROLES,
                self::CREATE_ROLES,
                self::UPDATE_ROLES,
                self::DELETE_ROLES,
                self::VIEW_SCHOOLS,
                self::CREATE_SCHOOLS,
                self::UPDATE_SCHOOLS,
                self::DELETE_SCHOOLS,
                self::VIEW_SCHOOL_MERGES,
                self::CREATE_SCHOOL_MERGES,
                self::VIEW_VILLAGES,
                self::CREATE_VILLAGES,
                self::UPDATE_VILLAGES,
                self::DELETE_VILLAGES,
                self::VIEW_VILLAGE_MERGES,
                self::CREATE_VILLAGE_MERGES,
                self::VIEW_IMPORTS,
                self::CREATE_IMPORTS,
                self::DELETE_IMPORTS,
                self::VIEW_EXPORTS,
                self::CREATE_EXPORTS,
                self::VIEW_TRASH,
                self::UPDATE_TRASH,
                self::DELETE_TRASH,
            ],
            default => [],
        };
    }
    // nhóm quyền về quản trị chủ hộ
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

    public static function villageScopedPermissions(): array
    {
        return [
            ...self::householdPermissions(),
            self::VIEW_IMPORTS,
            self::CREATE_IMPORTS,
            self::DELETE_IMPORTS,
            self::VIEW_EXPORTS,
            self::CREATE_EXPORTS,
            self::VIEW_TRASH,
            self::UPDATE_TRASH,
            self::DELETE_TRASH,
        ];
    }

    // quyền tiên quyết muốn xóa cần xem
    public static function prerequisites(): array
    {
        return [
            self::CREATE_HOUSEHOLDS => [self::VIEW_HOUSEHOLDS],
            self::UPDATE_HOUSEHOLDS => [self::VIEW_HOUSEHOLDS],
            self::DELETE_HOUSEHOLDS => [self::VIEW_HOUSEHOLDS],
            self::IMPORT_HOUSEHOLDS => [self::VIEW_HOUSEHOLDS],
            self::EXPORT_HOUSEHOLDS => [self::VIEW_HOUSEHOLDS],
            self::CREATE_USERS => [self::VIEW_USERS],
            self::UPDATE_USERS => [self::VIEW_USERS],
            self::DELETE_USERS => [self::VIEW_USERS],
            self::CREATE_ROLES => [self::VIEW_ROLES],
            self::UPDATE_ROLES => [self::VIEW_ROLES],
            self::DELETE_ROLES => [self::VIEW_ROLES],
            self::CREATE_SCHOOLS => [self::VIEW_SCHOOLS],
            self::UPDATE_SCHOOLS => [self::VIEW_SCHOOLS],
            self::DELETE_SCHOOLS => [self::VIEW_SCHOOLS],
            self::CREATE_SCHOOL_MERGES => [self::VIEW_SCHOOL_MERGES],
            self::CREATE_VILLAGES => [self::VIEW_VILLAGES],
            self::UPDATE_VILLAGES => [self::VIEW_VILLAGES],
            self::DELETE_VILLAGES => [self::VIEW_VILLAGES],
            self::CREATE_VILLAGE_MERGES => [self::VIEW_VILLAGE_MERGES],
            self::CREATE_IMPORTS => [self::VIEW_IMPORTS],
            self::DELETE_IMPORTS => [self::VIEW_IMPORTS],
            self::CREATE_EXPORTS => [self::VIEW_EXPORTS],
            self::UPDATE_TRASH => [self::VIEW_TRASH],
            self::DELETE_TRASH => [self::VIEW_TRASH],
        ];
    }

    /**
     * Maps permissions from the older broad permission model to the new granular actions.
     *
     * @return array<string, array<int, string>>
     */
    public static function legacyPermissionAliases(): array
    {
        return [
            self::MANAGE_USER_ACCOUNTS => [
                self::VIEW_USERS,
                self::CREATE_USERS,
                self::UPDATE_USERS,
                self::DELETE_USERS,
                self::MANAGE_ACCESS_CONTROL,
                self::VIEW_ROLES,
                self::CREATE_ROLES,
                self::UPDATE_ROLES,
                self::DELETE_ROLES,
            ],
            self::MANAGE_ACCESS_CONTROL => [self::VIEW_ROLES, self::CREATE_ROLES, self::UPDATE_ROLES, self::DELETE_ROLES],
            self::MANAGE_SCHOOLS => [
                self::VIEW_SCHOOLS,
                self::CREATE_SCHOOLS,
                self::UPDATE_SCHOOLS,
                self::DELETE_SCHOOLS,
                self::VIEW_SCHOOL_MERGES,
                self::CREATE_SCHOOL_MERGES,
            ],
            self::MANAGE_VILLAGES => [
                self::VIEW_VILLAGES,
                self::CREATE_VILLAGES,
                self::UPDATE_VILLAGES,
                self::DELETE_VILLAGES,
                self::VIEW_VILLAGE_MERGES,
                self::CREATE_VILLAGE_MERGES,
            ],
            self::IMPORT_HOUSEHOLDS => [self::VIEW_IMPORTS, self::CREATE_IMPORTS, self::DELETE_IMPORTS],
            self::EXPORT_HOUSEHOLDS => [self::VIEW_EXPORTS, self::CREATE_EXPORTS],
            self::DELETE_HOUSEHOLDS => [self::VIEW_TRASH, self::UPDATE_TRASH, self::DELETE_TRASH],
        ];
    }

    /**
     * Returns the legacy or granular permission names that can authorize an ability.
     *
     * @return array<int, string>
     */
    public static function permissionSources(string $permission): array
    {
        $aliases = self::legacyPermissionAliases();
        $requestedAliases = [
            self::MANAGE_USER_ACCOUNTS => [self::CREATE_USERS, self::UPDATE_USERS, self::DELETE_USERS],
            self::MANAGE_ACCESS_CONTROL => [
                self::CREATE_ROLES,
                self::UPDATE_ROLES,
                self::DELETE_ROLES,
                self::MANAGE_USER_ACCOUNTS,
            ],
            self::MANAGE_SCHOOLS => [self::CREATE_SCHOOLS, self::UPDATE_SCHOOLS, self::DELETE_SCHOOLS, self::CREATE_SCHOOL_MERGES],
            self::MANAGE_VILLAGES => [self::CREATE_VILLAGES, self::UPDATE_VILLAGES, self::DELETE_VILLAGES, self::CREATE_VILLAGE_MERGES],
            self::IMPORT_HOUSEHOLDS => [self::CREATE_IMPORTS],
            self::EXPORT_HOUSEHOLDS => [self::CREATE_EXPORTS],
        ];

        $sources = [$permission, ...($requestedAliases[$permission] ?? [])];
        foreach ($aliases as $legacy => $granularPermissions) {
            if (in_array($permission, $granularPermissions, true)) {
                $sources[] = $legacy;
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * Expands broad legacy permissions for frontends that consume the permission list.
     *
     * @param array<int, string> $permissions
     * @return array<int, string>
     */
    public static function expandLegacyPermissions(array $permissions): array
    {
        foreach ($permissions as $permission) {
            array_push($permissions, ... (self::legacyPermissionAliases()[$permission] ?? []));
        }

        return array_values(array_unique($permissions));
    }
    // gọi cái hàm tiên quyết để check trong mảng được view ko
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
    // return 1 list quyền mảng đủ có quyền xem

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
