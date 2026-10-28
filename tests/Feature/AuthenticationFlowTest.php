<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthenticationFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->string('scope_level')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->string('scope_level');
            $table->timestamps();
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id');
            $table->foreignId('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function test_guest_can_get_csrf_cookie_but_cannot_read_session(): void
    {
        $this->get('/api/v1/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');

        $this->getJson('/api/v1/session')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_api_login_creates_a_reusable_session_and_returns_json_user_data(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'api-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ])->fresh();

        $this->get('/api/v1/csrf-cookie')->assertNoContent();

        $this->postJson('/api/v1/login', [
            'username' => 'api-user',
            'password' => 'Correct-password-1',
            'remember' => true,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.username', 'api-user')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.refresh_token');

        $this->assertAuthenticatedAs($user, 'web');

        $this->getJson('/api/v1/session')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        Auth::forgetGuards();
        $this->getJson('/api/v1/session')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_api_login_rejects_invalid_credentials_as_json(): void
    {
        $this->postJson('/api/v1/login', [
            'username' => 'missing-user',
            'password' => 'incorrect',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'username');
    }

    public function test_api_logout_ends_the_session_and_subsequent_requests_are_unauthorized(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'api-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ])->fresh();
        $session = $this->actingAs($user, 'web')->getJson('/api/v1/session');
        $this->assertSame(200, $session->getStatusCode(), $session->getContent());

        $this->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Đăng xuất thành công.')
            ->assertJsonPath('data', null);

        Auth::forgetGuards();
        $this->getJson('/api/v1/session')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_inactive_account_is_logged_out_from_session_api(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'api-user',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $user->forceFill(['is_active' => false])->save();
        $user->refresh();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/session')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertGuest('web');
    }
}
