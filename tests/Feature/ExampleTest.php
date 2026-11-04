<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_guest_cannot_access_the_api_session(): void
    {
        $this->getJson('/api/v1/session')->assertUnauthorized();
    }
}
