<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tetranyble\Kinship\Http\Middleware\ValidateImpersonationSession;
use Tetranyble\Kinship\Impersonation\ImpersonationState;

class SupportImpersonationMiddleware extends ValidateImpersonationSession
{
    protected function allowsImpersonatedRequest(
        Request $request,
        Authenticatable $actor,
        Authenticatable $target,
        ImpersonationState $state,
    ): bool {
        if (! $actor instanceof Model || ! $target instanceof Model) {
            return false;
        }

        return $actor->getAttribute('mfa_verified_at') !== null
            && $target->getAttribute('locked_at') !== null;
    }
}
