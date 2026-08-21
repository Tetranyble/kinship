<?php

namespace Tetranyble\Kinship\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Tetranyble\Kinship\Impersonation\ImpersonationManager;
use Tetranyble\Kinship\Impersonation\ImpersonationState;

trait InteractsWithImpersonation
{
    public function impersonate(Authenticatable $target, string $reason, ?string $guard = null): ImpersonationState
    {
        return app(ImpersonationManager::class)->startFor($this, $target, $reason, $guard);
    }

    public function isImpersonating(): bool
    {
        return app(ImpersonationManager::class)->active();
    }

    public function impersonator(): ?Authenticatable
    {
        return app(ImpersonationManager::class)->actor();
    }

    public function stopImpersonating(): ?ImpersonationState
    {
        return app(ImpersonationManager::class)->stop();
    }
}
