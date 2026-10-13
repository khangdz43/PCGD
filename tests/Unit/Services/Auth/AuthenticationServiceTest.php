<?php

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\AuthenticationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthenticationServiceTest extends TestCase
{
    public function test_successful_login_clears_the_normalized_throttle_key(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->with('nguoi dung|127.0.0.1', 5)
            ->andReturnFalse();
        Auth::shouldReceive('attempt')
            ->once()
            ->with(['username' => 'Người Dùng', 'password' => 'secret'], true)
            ->andReturnTrue();
        Auth::shouldReceive('user')
            ->once()
            ->andReturn((object) ['is_active' => true]);
        RateLimiter::shouldReceive('clear')
            ->once()
            ->with('nguoi dung|127.0.0.1');

        (new AuthenticationService)->authenticate('Người Dùng', 'secret', '127.0.0.1', true);

        $this->addToAssertionCount(1);
    }

    public function test_invalid_credentials_increment_the_throttle_and_return_a_validation_error(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')->once()->andReturnFalse();
        Auth::shouldReceive('attempt')->once()->andReturnFalse();
        RateLimiter::shouldReceive('hit')
            ->once()
            ->with('user|127.0.0.1', 60);

        try {
            (new AuthenticationService)->authenticate('User', 'wrong', '127.0.0.1', false);
            self::fail('Expected invalid credentials to raise a validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['Thông tin đăng nhập không chính xác.'],
                $exception->errors()['username'],
            );
        }
    }

    public function test_throttled_login_is_rejected_without_attempting_authentication(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->with('user|127.0.0.1', 5)
            ->andReturnTrue();
        Auth::shouldReceive('attempt')->never();
        RateLimiter::shouldReceive('hit')->never();

        try {
            (new AuthenticationService)->authenticate('User', 'secret', '127.0.0.1', false);
            self::fail('Expected throttled login to raise a validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['Bạn đã đăng nhập sai quá nhiều lần. Vui lòng thử lại sau.'],
                $exception->errors()['username'],
            );
        }
    }

    public function test_inactive_account_is_logged_out_and_counted_as_a_failed_attempt(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')->once()->andReturnFalse();
        Auth::shouldReceive('attempt')->once()->andReturnTrue();
        Auth::shouldReceive('user')
            ->once()
            ->andReturn((object) ['is_active' => false]);
        Auth::shouldReceive('logout')->once();
        RateLimiter::shouldReceive('hit')
            ->once()
            ->with('user|127.0.0.1', 60);
        RateLimiter::shouldReceive('clear')->never();

        try {
            (new AuthenticationService)->authenticate('User', 'secret', '127.0.0.1', false);
            self::fail('Expected inactive account to raise a validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['Tài khoản đã bị vô hiệu hóa.'],
                $exception->errors()['username'],
            );
        }
    }
}
