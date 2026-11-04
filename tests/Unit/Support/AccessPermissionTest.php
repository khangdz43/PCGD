<?php

namespace Tests\Unit\Support;

use App\Support\AccessPermission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AccessPermissionTest extends TestCase
{
    public function test_definitions_cover_the_known_permissions_and_use_the_current_group_label(): void
    {
        $definitions = AccessPermission::definitions();
        $allPermissions = array_values(array_unique(array_merge(
            ...array_map(
                AccessPermission::permissionsForScope(...),
                ['global', 'province', 'commune'],
            ),
        )));

        sort($allPermissions);
        $definitionKeys = array_keys($definitions);
        sort($definitionKeys);

        self::assertSame($allPermissions, $definitionKeys);
        self::assertSame('Quản lý nhóm người dùng', $definitions[AccessPermission::MANAGE_ACCESS_CONTROL]);
    }

    #[DataProvider('scopePermissions')]
    public function test_permissions_are_limited_to_the_expected_scope(string $scope, array $expected): void
    {
        self::assertSame($expected, AccessPermission::permissionsForScope($scope));
    }

    public static function scopePermissions(): array
    {
        return [
            'central' => ['global', [
                AccessPermission::VIEW_HOUSEHOLDS,
                AccessPermission::CREATE_HOUSEHOLDS,
                AccessPermission::UPDATE_HOUSEHOLDS,
                AccessPermission::DELETE_HOUSEHOLDS,
                AccessPermission::IMPORT_HOUSEHOLDS,
                AccessPermission::EXPORT_HOUSEHOLDS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
                AccessPermission::MANAGE_USER_ACCOUNTS,
                AccessPermission::VIEW_ORGANIZATION_ACCOUNTS,
                AccessPermission::VIEW_USERS,
                AccessPermission::CREATE_USERS,
                AccessPermission::UPDATE_USERS,
                AccessPermission::DELETE_USERS,
                AccessPermission::VIEW_ROLES,
                AccessPermission::CREATE_ROLES,
                AccessPermission::UPDATE_ROLES,
                AccessPermission::DELETE_ROLES,
                AccessPermission::VIEW_SCHOOLS,
                AccessPermission::CREATE_SCHOOLS,
                AccessPermission::UPDATE_SCHOOLS,
                AccessPermission::DELETE_SCHOOLS,
                AccessPermission::VIEW_VILLAGES,
                AccessPermission::CREATE_VILLAGES,
                AccessPermission::UPDATE_VILLAGES,
                AccessPermission::DELETE_VILLAGES,
                AccessPermission::VIEW_IMPORTS,
                AccessPermission::CREATE_IMPORTS,
                AccessPermission::DELETE_IMPORTS,
                AccessPermission::VIEW_EXPORTS,
                AccessPermission::CREATE_EXPORTS,
                AccessPermission::VIEW_TRASH,
                AccessPermission::UPDATE_TRASH,
                AccessPermission::DELETE_TRASH,
            ]],
            'province' => ['province', [
                AccessPermission::MANAGE_SCHOOLS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
                AccessPermission::MANAGE_USER_ACCOUNTS,
                AccessPermission::VIEW_USERS,
                AccessPermission::CREATE_USERS,
                AccessPermission::UPDATE_USERS,
                AccessPermission::DELETE_USERS,
                AccessPermission::VIEW_ROLES,
                AccessPermission::CREATE_ROLES,
                AccessPermission::UPDATE_ROLES,
                AccessPermission::DELETE_ROLES,
                AccessPermission::VIEW_SCHOOLS,
                AccessPermission::CREATE_SCHOOLS,
                AccessPermission::UPDATE_SCHOOLS,
                AccessPermission::DELETE_SCHOOLS,
                AccessPermission::VIEW_SCHOOL_MERGES,
                AccessPermission::CREATE_SCHOOL_MERGES,
            ]],
            'commune' => ['commune', [
                AccessPermission::VIEW_HOUSEHOLDS,
                AccessPermission::CREATE_HOUSEHOLDS,
                AccessPermission::UPDATE_HOUSEHOLDS,
                AccessPermission::DELETE_HOUSEHOLDS,
                AccessPermission::IMPORT_HOUSEHOLDS,
                AccessPermission::EXPORT_HOUSEHOLDS,
                AccessPermission::MANAGE_VILLAGES,
                AccessPermission::MANAGE_SCHOOLS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
                AccessPermission::MANAGE_USER_ACCOUNTS,
                AccessPermission::VIEW_USERS,
                AccessPermission::CREATE_USERS,
                AccessPermission::UPDATE_USERS,
                AccessPermission::DELETE_USERS,
                AccessPermission::VIEW_ROLES,
                AccessPermission::CREATE_ROLES,
                AccessPermission::UPDATE_ROLES,
                AccessPermission::DELETE_ROLES,
                AccessPermission::VIEW_SCHOOLS,
                AccessPermission::CREATE_SCHOOLS,
                AccessPermission::UPDATE_SCHOOLS,
                AccessPermission::DELETE_SCHOOLS,
                AccessPermission::VIEW_SCHOOL_MERGES,
                AccessPermission::CREATE_SCHOOL_MERGES,
                AccessPermission::VIEW_VILLAGES,
                AccessPermission::CREATE_VILLAGES,
                AccessPermission::UPDATE_VILLAGES,
                AccessPermission::DELETE_VILLAGES,
                AccessPermission::VIEW_VILLAGE_MERGES,
                AccessPermission::CREATE_VILLAGE_MERGES,
                AccessPermission::VIEW_IMPORTS,
                AccessPermission::CREATE_IMPORTS,
                AccessPermission::DELETE_IMPORTS,
                AccessPermission::VIEW_EXPORTS,
                AccessPermission::CREATE_EXPORTS,
                AccessPermission::VIEW_TRASH,
                AccessPermission::UPDATE_TRASH,
                AccessPermission::DELETE_TRASH,
            ]],
            'unknown scope' => ['unknown', []],
        ];
    }

    public function test_household_permissions_include_view_and_all_supported_actions(): void
    {
        self::assertSame([
            AccessPermission::VIEW_HOUSEHOLDS,
            AccessPermission::CREATE_HOUSEHOLDS,
            AccessPermission::UPDATE_HOUSEHOLDS,
            AccessPermission::DELETE_HOUSEHOLDS,
            AccessPermission::IMPORT_HOUSEHOLDS,
            AccessPermission::EXPORT_HOUSEHOLDS,
        ], AccessPermission::householdPermissions());
    }

    public function test_prerequisite_permissions_are_implied_by_the_required_permission(): void
    {
        self::assertSame(
            [
                AccessPermission::CREATE_HOUSEHOLDS,
                AccessPermission::UPDATE_HOUSEHOLDS,
                AccessPermission::DELETE_HOUSEHOLDS,
                AccessPermission::IMPORT_HOUSEHOLDS,
                AccessPermission::EXPORT_HOUSEHOLDS,
            ],
            AccessPermission::impliedPermissions(AccessPermission::VIEW_HOUSEHOLDS),
        );
        self::assertSame(
            [],
            AccessPermission::impliedPermissions(AccessPermission::MANAGE_ACCESS_CONTROL),
        );
        self::assertSame([], AccessPermission::impliedPermissions('unknown-permission'));
    }

    public function test_with_prerequisites_adds_missing_prerequisites_without_duplicates(): void
    {
        self::assertSame([
            AccessPermission::CREATE_HOUSEHOLDS,
            AccessPermission::DELETE_HOUSEHOLDS,
            AccessPermission::VIEW_HOUSEHOLDS,
        ], AccessPermission::withPrerequisites([
            AccessPermission::CREATE_HOUSEHOLDS,
            AccessPermission::CREATE_HOUSEHOLDS,
            AccessPermission::DELETE_HOUSEHOLDS,
        ]));
    }

    public function test_with_prerequisites_preserves_existing_prerequisites_and_order(): void
    {
        self::assertSame([
            AccessPermission::VIEW_HOUSEHOLDS,
            AccessPermission::CREATE_HOUSEHOLDS,
            AccessPermission::MANAGE_ACCESS_CONTROL,
            AccessPermission::MANAGE_USER_ACCOUNTS,
        ], AccessPermission::withPrerequisites([
            AccessPermission::VIEW_HOUSEHOLDS,
            AccessPermission::CREATE_HOUSEHOLDS,
            AccessPermission::MANAGE_ACCESS_CONTROL,
            AccessPermission::MANAGE_USER_ACCOUNTS,
        ]));
    }

    public function test_legacy_permissions_expand_to_granular_actions_without_escalating_view_only_permissions(): void
    {
        self::assertSame([
            AccessPermission::MANAGE_USER_ACCOUNTS,
            AccessPermission::VIEW_USERS,
            AccessPermission::CREATE_USERS,
            AccessPermission::UPDATE_USERS,
            AccessPermission::DELETE_USERS,
            AccessPermission::MANAGE_ACCESS_CONTROL,
            AccessPermission::VIEW_ROLES,
            AccessPermission::CREATE_ROLES,
            AccessPermission::UPDATE_ROLES,
            AccessPermission::DELETE_ROLES,
        ], AccessPermission::expandLegacyPermissions([AccessPermission::MANAGE_USER_ACCOUNTS]));

        self::assertSame(
            [AccessPermission::VIEW_USERS],
            AccessPermission::expandLegacyPermissions([AccessPermission::VIEW_USERS]),
        );
        self::assertSame(
            [AccessPermission::CREATE_USERS, AccessPermission::MANAGE_USER_ACCOUNTS],
            AccessPermission::permissionSources(AccessPermission::CREATE_USERS),
        );
    }
}
