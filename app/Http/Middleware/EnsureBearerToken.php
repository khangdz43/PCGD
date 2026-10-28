<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $request->user()?->currentAccessToken();
        if (! ($accessToken instanceof PersonalAccessToken)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
