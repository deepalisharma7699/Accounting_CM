<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path is the public page, and the dashboard is behind it.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertOk();

        $this->get('/dashboard')->assertOk();
    }
}
