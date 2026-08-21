<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Tetranyble\Kinship\Http\Middleware\ValidateImpersonationSession;
use Tetranyble\Kinship\Tests\PackageTestCase;

class ImpersonationConfigurationTest extends PackageTestCase
{
    public function test_impersonation_and_automatic_route_integration_are_opt_in(): void
    {
        $router = app('router');

        $this->assertFalse((bool) config('kinship.impersonation.enabled'));
        $this->assertFalse((bool) config('kinship.impersonation.auto_middleware'));
        $this->assertNull(config('kinship.impersonation.stateless_broker'));
        $this->assertSame(
            ValidateImpersonationSession::class,
            $router->getMiddleware()['kinship.impersonation.valid'] ?? null,
        );
        $this->assertArrayNotHasKey('kinship.impersonation.stateless', $router->getMiddleware());
        $this->assertNotContains(
            ValidateImpersonationSession::class,
            $router->getMiddlewareGroups()['web'] ?? [],
        );
    }
}
