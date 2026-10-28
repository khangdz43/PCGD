<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticationService
{
    public function authenticate(string $username, string $password, string $ip, bool $remember): void
    {
        $throttleKey = Str::transliterate(Str::lower($username).'|'.$ip);
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'username' => 'Bạn đã đăng nhập sai quá nhiều lần. Vui lòng thử lại sau.',
            ]);
        }

        if (! Auth::attempt(['username' => $username, 'password' => $password], $remember)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'username' => 'Thông tin đăng nhập không chính xác.',
            ]);
        }

        if (! Auth::user()->is_active) {
            Auth::logout();
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'username' => 'Tài khoản đã bị vô hiệu hóa.',
            ]);
        }

        RateLimiter::clear($throttleKey);
    }
}
