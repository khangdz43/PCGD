<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Households\ExportHouseholdsRequest;
use App\Http\Requests\Households\StoreHouseholdImportRequest;
use App\Http\Requests\Profile\UpdateAccountPasswordRequest;
use Carbon\Carbon;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class FormRequestRulesTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_login_requires_a_bounded_username_and_password(): void
    {
        $request = new LoginRequest;

        self::assertTrue($request->authorize());
        self::assertSame([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ], $request->rules());
    }

    public function test_password_update_requires_current_confirmed_distinct_and_long_passwords(): void
    {
        $request = new UpdateAccountPasswordRequest;
        $rules = $request->rules();

        self::assertSame(['required', 'current_password'], $rules['current_password']);
        self::assertSame(['required', 'confirmed', 'different:current_password'], array_slice($rules['password'], 0, 3));
        self::assertInstanceOf(Password::class, $rules['password'][3]);
        self::assertSame([
            'current_password.current_password' => 'Mật khẩu hiện tại không chính xác.',
            'password.confirmed' => 'Xác nhận mật khẩu mới không khớp.',
            'password.different' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
            'password.min' => 'Mật khẩu mới phải có ít nhất 12 ký tự.',
        ], $request->messages());
    }

    public function test_export_filters_allow_optional_ids_and_only_known_filter_types(): void
    {
        $rules = (new ExportHouseholdsRequest)->rules();

        self::assertSame(['sometimes', 'array'], $rules['household_ids']);
        self::assertSame(['integer', 'distinct', 'exists:households,id'], $rules['household_ids.*']);
        self::assertSame(['nullable', 'string'], $rules['school_code']);
        self::assertSame(['nullable', 'string'], $rules['school_year']);
    }

    public function test_import_request_limits_upload_to_the_current_school_year_and_geography_fields(): void
    {
        Carbon::setTestNow('2025-07-31');
        $request = new StoreHouseholdImportRequest;
        $rules = $request->rules();

        self::assertTrue($request->authorize());
        self::assertSame(['required', 'file', 'mimes:xls,xlsx', 'max:20480'], $rules['file']);
        self::assertSame('required', $rules['school_year'][0]);
        self::assertSame('in:"2024-2025"', (string) $rules['school_year'][1]);
        self::assertSame(['required', 'exists:provinces,code'], $rules['province_code']);
        self::assertSame('required', $rules['commune_code'][0]);
        self::assertSame('required', $rules['village_code'][0]);
    }
}
