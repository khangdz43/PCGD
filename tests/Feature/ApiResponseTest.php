<?php

namespace Tests\Feature;

use App\Support\ApiResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_swagger_and_openapi_routes_are_removed(): void
    {
        $this->get('/api/docs')->assertNotFound();
        $this->get('/api/openapi.yaml')->assertNotFound();
        $this->get('/docs/api.json')->assertNotFound();
    }

    public function test_unlabeled_success_responses_use_localized_messages(): void
    {
        $created = ApiResponse::fromJsonResponse(response()->json(['data' => ['id' => 1]], 201));
        $retrieved = ApiResponse::fromJsonResponse(response()->json(['data' => ['id' => 1]], 200));

        $this->assertSame('Tạo mới thành công.', $created->getData(true)['message']);
        $this->assertSame('Yêu cầu đã được xử lý thành công.', $retrieved->getData(true)['message']);
    }

    public function test_nested_paginated_lists_are_normalized_into_top_level_meta(): void
    {
        $response = ApiResponse::fromJsonResponse(response()->json([
            'imports' => [
                'data' => [['id' => 1]],
                'current_page' => 1,
                'last_page' => 2,
                'per_page' => 10,
                'total' => 11,
            ],
            'queue_total' => 3,
        ]));
        $payload = $response->getData(true);

        $this->assertSame([['id' => 1]], $payload['data']['imports']);
        $this->assertSame(3, $payload['data']['queue_total']);
        $this->assertSame(1, $payload['meta']['current_page']);
        $this->assertSame(2, $payload['meta']['last_page']);
        $this->assertSame(10, $payload['meta']['per_page']);
        $this->assertSame(11, $payload['meta']['total']);
    }

    public function test_custom_http_exception_message_is_preserved_in_api_error_envelope(): void
    {
        $response = ApiResponse::fromException(new NotFoundHttpException('Không tìm thấy tệp mẫu này.'));
        $payload = $response->getData(true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame(404, $payload['status_code']);
        $this->assertSame('Không tìm thấy tệp mẫu này.', $payload['message']);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
    }
}
