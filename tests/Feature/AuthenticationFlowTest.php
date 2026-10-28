<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
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
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
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

    public function test_login_page_is_available_to_guests_and_login_validates_required_fields(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('name="password"', false);

        $this->from(route('login'))
            ->post(route('login.store'), [])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username', 'password']);
    }

    public function test_valid_credentials_authenticate_and_redirect_to_the_intended_home_page(): void
    {
        $user = User::create([
            'name' => 'Người dùng thử',
            'username' => 'test-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ]);

        $this->post(route('login.store'), [
            'username' => 'test-user',
            'password' => 'Correct-password-1',
            'remember' => '1',
        ])
            ->assertRedirect(route('index'))
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_return_a_username_error_and_preserve_guest_state(): void
    {
        User::create([
            'name' => 'Người dùng thử',
            'username' => 'test-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ]);

        $this->from(route('login'))
            ->post(route('login.store'), [
                'username' => 'test-user',
                'password' => 'incorrect',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'username' => 'Thông tin đăng nhập không chính xác.',
            ]);

        $this->assertGuest();
    }

    public function test_authenticated_user_can_log_out_and_visit_login_again(): void
    {
        $user = User::create([
            'name' => 'Người dùng thử',
            'username' => 'test-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get(route('login'))->assertOk();
    }

    public function test_api_login_issues_a_bearer_token_without_creating_a_web_session(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'api-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/tokens', [
            'username' => 'api-user',
            'password' => 'Correct-password-1',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status_code', 201)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.username', 'api-user')
            ->assertJsonMissingPath('data.user.password');

        $this->assertGuest();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withToken($response->json('data.access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status_code', 200)
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_json_api_login_returns_validation_errors_for_invalid_credentials(): void
    {
        $this->postJson('/api/v1/auth/tokens', [
            'username' => 'missing-user',
            'password' => 'incorrect',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'username')
            ->assertJsonPath('error.details.0.issue', 'Thông tin đăng nhập không chính xác.');
    }

    public function test_api_requires_a_bearer_token_even_when_a_web_session_exists(): void
    {
        $this->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $user = User::create([
            'name' => 'Người dùng web',
            'username' => 'web-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_api_logout_revokes_the_current_bearer_token(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'api-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ]);
        $token = $user->createToken('test-token', ['*'], now()->addHour())->plainTextToken;

        $this->withToken($token)
            ->deleteJson('/api/v1/auth/tokens/current')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status_code', 200)
            ->assertJsonPath('message', 'Token revoked successfully')
            ->assertJsonPath('data', null);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_api_revokes_a_token_when_its_account_is_disabled(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'api-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        $user->forceFill(['is_active' => false])->save();

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 403)
            ->assertJsonPath('message', 'Tài khoản đã bị vô hiệu hóa.')
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
