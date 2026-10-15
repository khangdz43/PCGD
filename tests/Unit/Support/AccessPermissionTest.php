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
                ['global', 'province', 'commune', 'village'],
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
            ]],
            'province' => ['province', [
                AccessPermission::MANAGE_SCHOOLS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
                AccessPermission::MANAGE_USER_ACCOUNTS,
            ]],
            'commune' => ['commune', [
                AccessPermission::VIEW_HOUSEHOLDS,
                AccessPermission::CREATE_HOUSEHOLDS,
                AccessPermission::UPDATE_HOUSEHOLDS,
                AccessPermission::DELETE_HOUSEHOLDS,
                AccessPermission::IMPORT_HOUSEHOLDS,
                AccessPermission::EXPORT_HOUSEHOLDS,
                AccessPermission::MANAGE_VILLAGES,
                AccessPermission::MANAGE_ACCESS_CONTROL,
                AccessPermission::MANAGE_USER_ACCOUNTS,
            ]],
            'village' => ['village', [
                AccessPermission::VIEW_HOUSEHOLDS,
                AccessPermission::CREATE_HOUSEHOLDS,
                AccessPermission::UPDATE_HOUSEHOLDS,
                AccessPermission::DELETE_HOUSEHOLDS,
                AccessPermission::IMPORT_HOUSEHOLDS,
                AccessPermission::EXPORT_HOUSEHOLDS,
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
            [AccessPermission::MANAGE_USER_ACCOUNTS],
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
}
