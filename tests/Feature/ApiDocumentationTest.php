<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_swagger_ui_and_openapi_spec_are_publicly_available(): void
    {
        $this->get('/api/docs')
            ->assertOk()
            ->assertSee('Swagger UI - PCGD API')
            ->assertSee('openapi.yaml');

        $this->get('/api/openapi.yaml')
            ->assertOk()
            ->assertHeader('content-type', 'application/yaml; charset=UTF-8')
            ->assertSee('openapi: 3.0.3')
            ->assertSee('/auth/tokens');
    }
}
