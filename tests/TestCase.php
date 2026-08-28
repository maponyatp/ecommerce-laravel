<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        Livewire::flushState();

        // Regression tests must never contact real supplier/payment endpoints.
        Http::preventStrayRequests();
    }
}
