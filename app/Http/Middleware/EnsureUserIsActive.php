<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    // mọi middle đều phải có method handle
    // xử lí thằng not active
    public function handle(Request $request, Closure $next): Response
    {
        // lấy instance người dùng log in hiện tại
        // $request->user()  response model User
        if ($request->user() && ! $request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Tài khoản đã bị vô hiệu hóa.',
                    'errors' => ['username' => ['Tài khoản đã bị vô hiệu hóa.']],
                ], 403);
            }

            return redirect()->route('login')->withErrors([
                'username' => 'Tài khoản đã bị vô hiệu hóa.',
            ]);
        }

        // cho đi tiếp
        return $next($request);
    }
}
