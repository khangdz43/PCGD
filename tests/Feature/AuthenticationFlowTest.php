<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
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

    public function test_json_api_login_authenticates_without_returning_a_redirect(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'api-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/csrf-cookie')
            ->assertOk()
            ->assertJsonStructure(['csrf_token']);

        $this->postJson('/api/v1/login', [
            'username' => 'api-user',
            'password' => 'Correct-password-1',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.username', 'api-user')
            ->assertJsonMissingPath('user.password');

        $this->assertAuthenticatedAs($user);
    }

    public function test_json_api_login_returns_validation_errors_for_invalid_credentials(): void
    {
        $this->postJson('/api/v1/login', [
            'username' => 'missing-user',
            'password' => 'incorrect',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_json_api_session_requires_authentication(): void
    {
        $this->getJson('/api/v1/session')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
