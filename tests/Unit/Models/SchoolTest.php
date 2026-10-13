<?php

namespace Tests\Unit\Models;

use App\Models\School;
use PHPUnit\Framework\TestCase;

class SchoolTest extends TestCase
{
    public function test_display_name_trims_the_school_name(): void
    {
        $school = new School(['name' => '  Trường Tiểu học A  ', 'code' => 'SCH01']);

        self::assertSame('Trường Tiểu học A', $school->display_name);
    }

    public function test_display_name_uses_a_fallback_for_an_empty_school_name(): void
    {
        $school = new School(['name' => '  ', 'code' => 'SCH01']);

        self::assertSame('Chưa cập nhật tên (SCH01)', $school->display_name);
    }
}
