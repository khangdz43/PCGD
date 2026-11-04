<?php

namespace Tests\Unit\Services\Education;

use App\Models\User;
use App\Services\Education\SchoolService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchoolServiceTest extends TestCase
{
    #[DataProvider('scopeSchoolLevels')]
    public function test_school_levels_are_limited_by_account_scope(string $scope, array $expected): void
    {
        $user = new User;
        $user->setRawAttributes(['scope_level' => $scope]);
        $user->setRelation('roles', new Collection);

        self::assertSame($expected, (new SchoolService)->levelsForUser($user));
    }

    public static function scopeSchoolLevels(): array
    {
        return [
            'province' => ['province', ['MN', 'TH', 'THCS', 'THPT']],
            'commune' => ['commune', ['MN', 'TH', 'THCS']],
            'unknown scope' => ['unknown', []],
        ];
    }
}
