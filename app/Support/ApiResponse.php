<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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

            foreach ($data as $key => $value) {
                if (
                    ! is_array($value)
                    || ! array_key_exists('data', $value)
                    || ! array_key_exists('current_page', $value)
                    || ! array_key_exists('total', $value)
                ) {
                    continue;
                }

                $meta = array_diff_key($value, ['data' => true]);
                $data[$key] = $value['data'];

                break;
            }
        } else {
            $meta = array_diff_key($payload, array_flip(['data', 'message']));
        }

        return self::success($data, $message, $status, $meta);
    }

    public static function fromException(Throwable $exception, ?Request $request = null): JsonResponse
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
            $exception instanceof ValidationException => 'Dữ liệu không hợp lệ.',
            $exception instanceof AuthenticationException => 'Bạn chưa đăng nhập hoặc token không hợp lệ.',
            $exception instanceof AuthorizationException => 'Bạn không có quyền thực hiện thao tác này.',
            $status === Response::HTTP_NOT_FOUND => self::notFoundMessage($exception, $request),
            $status === Response::HTTP_METHOD_NOT_ALLOWED => 'Phương thức HTTP không được hỗ trợ cho tài nguyên này.',
            $status === Response::HTTP_TOO_MANY_REQUESTS => 'Bạn gửi quá nhiều yêu cầu. Vui lòng thử lại sau.',
            $status >= Response::HTTP_INTERNAL_SERVER_ERROR => 'Đã xảy ra lỗi máy chủ. Vui lòng thử lại sau.',
            default => self::customHttpMessage($exception, $status) ?? 'Yêu cầu không thể thực hiện.',
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
            Response::HTTP_METHOD_NOT_ALLOWED => 'METHOD_NOT_ALLOWED',
            Response::HTTP_TOO_MANY_REQUESTS => 'RATE_LIMITED',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'VALIDATION_FAILED',
            default => $status >= Response::HTTP_INTERNAL_SERVER_ERROR ? 'SERVER_ERROR' : 'REQUEST_FAILED',
        };
    }

    private static function notFoundMessage(Throwable $exception, ?Request $request): string
    {
        $modelMessages = [
            \App\Models\Village::class => 'Không tìm thấy thôn/xóm này.',
            \App\Models\Commune::class => 'Không tìm thấy xã này.',
            \App\Models\Province::class => 'Không tìm thấy tỉnh này.',
            \App\Models\School::class => 'Không tìm thấy trường học này.',
            \App\Models\Household::class => 'Không tìm thấy hộ gia đình này.',
            \App\Models\Person::class => 'Không tìm thấy nhân khẩu này.',
            \App\Models\Role::class => 'Không tìm thấy nhóm quyền này.',
            \App\Models\User::class => 'Không tìm thấy tài khoản này.',
            \App\Models\ImportLog::class => 'Không tìm thấy lịch sử nhập dữ liệu này.',
        ];

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ModelNotFoundException) {
                $model = $current->getModel();
                if ($model !== null && isset($modelMessages[$model])) {
                    return $modelMessages[$model];
                }
            }
        }

        $customMessage = self::customHttpMessage($exception, Response::HTTP_NOT_FOUND);
        if ($customMessage !== null) {
            return $customMessage;
        }

        if ($request?->is('api/v1/villages/*')) {
            return $modelMessages[\App\Models\Village::class];
        }

        return 'Không tìm thấy tài nguyên yêu cầu.';
    }

    private static function customHttpMessage(Throwable $exception, int $status): ?string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof HttpExceptionInterface || $current->getMessage() === '') {
                continue;
            }

            $defaultMessage = Response::$statusTexts[$status] ?? null;
            if ($defaultMessage === null || strcasecmp($current->getMessage(), $defaultMessage) !== 0) {
                return $current->getMessage();
            }
        }

        return null;
    }

    private static function successMessage(int $status): string
    {
        return $status === Response::HTTP_CREATED
            ? 'Tạo mới thành công.'
            : 'Yêu cầu đã được xử lý thành công.';
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
