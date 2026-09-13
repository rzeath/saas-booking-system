<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:8080');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withoutMiddleware(ThrottleRequests::class);
    }
}
