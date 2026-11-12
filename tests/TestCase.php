<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Inertia + SPA mode sends X-XSRF-TOKEN from the cookie the browser reads; the
        // test client has no cookie, so the check fails with 419. Disable the verifier
        // for feature tests (unit/arch unaffected).
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
