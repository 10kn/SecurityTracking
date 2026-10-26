<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sleeping is faked in every test: a Google Chat message run without a queue worker waits for its space's turn
     * (see SendChatNotification).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }
}
