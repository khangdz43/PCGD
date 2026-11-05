<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FormatApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response = ApiResponse::fromJsonResponse($response);
        }

        if ($request->is('api/v1/users')) {
            $duration = (hrtime(true) - $startedAt) / 1_000_000;
            $response->headers->set('Server-Timing', sprintf('app;dur=%.1f', $duration));
        }

        return $response;
    }
}
