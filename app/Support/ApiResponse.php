<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class ApiResponse
{
    /**
     * @param array<string, mixed> $meta
     */
    public static function success(
        mixed $data = null,
        string $message = 'Request completed successfully',
        int $status = Response::HTTP_OK,
        array $meta = [],
    ): JsonResponse {
        $body = [
            'success' => true,
            'status_code' => $status,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    /**
     * @param array<int, array{field: string, issue: string}> $details
     */
    public static function failure(
        string $message,
        string $code,
        array $details = [],
        int $status = Response::HTTP_BAD_REQUEST,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'status_code' => $status,
            'message' => $message,
            'error' => [
                'code' => $code,
                'details' => $details,
            ],
        ], $status);
    }

    public static function fromJsonResponse(JsonResponse $response): JsonResponse
    {
        $status = $response->getStatusCode();
        $payload = $response->getData(true);

        if (! is_array($payload)) {
            $payload = ['data' => $payload];
        }

        if (array_key_exists('success', $payload) && array_key_exists('status_code', $payload)) {
            return $response;
        }

        if ($status >= Response::HTTP_BAD_REQUEST) {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

            return self::failure(
                (string) ($payload['message'] ?? 'Request failed'),
                (string) ($error['code'] ?? self::errorCode($status)),
                self::errorDetails($payload),
                $status,
            );
        }

        if ($status === Response::HTTP_NO_CONTENT) {
            $status = Response::HTTP_OK;
        }

        $message = (string) ($payload['message'] ?? self::successMessage($status));
        $data = $payload['data'] ?? null;
        $meta = [];

        if (! array_key_exists('data', $payload)) {
            $data = $payload;
            unset($data['message']);
        } else {
            $meta = array_diff_key($payload, array_flip(['data', 'message']));
        }

        return self::success($data, $message, $status, $meta);
    }

    public static function fromException(Throwable $exception): JsonResponse
    {
        $status = match (true) {
            $exception instanceof ValidationException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $exception instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            $exception instanceof AuthorizationException => Response::HTTP_FORBIDDEN,
            $exception instanceof ModelNotFoundException => Response::HTTP_NOT_FOUND,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };

        $message = match (true) {
            $exception instanceof ValidationException => 'Validation failed',
            $exception instanceof AuthenticationException => 'Unauthenticated.',
            $exception instanceof AuthorizationException => 'This action is unauthorized.',
            $exception instanceof ModelNotFoundException => 'Resource not found',
            $status >= Response::HTTP_INTERNAL_SERVER_ERROR => 'Server error',
            default => $exception->getMessage() ?: 'Request failed',
        };

        $details = $exception instanceof ValidationException
            ? self::validationDetails($exception)
            : [];

        return self::failure($message, self::errorCode($status), $details, $status);
    }

    private static function errorCode(int $status): string
    {
        return match ($status) {
            Response::HTTP_UNAUTHORIZED => 'UNAUTHENTICATED',
            Response::HTTP_FORBIDDEN => 'FORBIDDEN',
            Response::HTTP_NOT_FOUND => 'NOT_FOUND',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'VALIDATION_FAILED',
            default => $status >= Response::HTTP_INTERNAL_SERVER_ERROR ? 'SERVER_ERROR' : 'REQUEST_FAILED',
        };
    }

    private static function successMessage(int $status): string
    {
        return $status === Response::HTTP_CREATED
            ? 'Resource created successfully'
            : 'Request completed successfully';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{field: string, issue: string}>
     */
    private static function errorDetails(array $payload): array
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        if (isset($error['details']) && is_array($error['details'])) {
            return $error['details'];
        }

        $details = [];
        foreach (($payload['errors'] ?? []) as $field => $messages) {
            foreach ((array) $messages as $issue) {
                $details[] = ['field' => (string) $field, 'issue' => (string) $issue];
            }
        }

        return $details;
    }

    /**
     * @return array<int, array{field: string, issue: string}>
     */
    private static function validationDetails(ValidationException $exception): array
    {
        $details = [];
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $issue) {
                $details[] = ['field' => (string) $field, 'issue' => (string) $issue];
            }
        }

        return $details;
    }
}
