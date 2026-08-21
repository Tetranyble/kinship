<?php

namespace Tetranyble\Kinship\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Tetranyble\Kinship\Contracts\StatelessImpersonationTokenBroker;
use Tetranyble\Kinship\Impersonation\ImpersonationManager;
use Tetranyble\Kinship\Impersonation\ImpersonationState;

class ValidateStatelessImpersonation
{
    public function __construct(
        private readonly ImpersonationManager $impersonation,
        private readonly StatelessImpersonationTokenBroker $tokens,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->impersonation->enabled()) {
            return $this->invalid($request, 403, 'User impersonation is disabled.');
        }

        $state = $this->resolveVerifiedState($request);
        if ($state === null) {
            return $this->invalid($request, 401, 'A valid stateless impersonation credential is required.');
        }

        if ($state->expired()) {
            return $this->invalid($request, 401, 'The stateless impersonation credential has expired.');
        }

        if (! $this->impersonation->currentUserMatchesTarget($state)) {
            return $this->invalid($request, 403, 'The impersonation grant does not match the authenticated user.');
        }

        $actor = $this->impersonation->actor($state);
        $target = $this->impersonation->target($state);
        if ($actor === null || $target === null) {
            return $this->invalid($request, 403, 'The impersonation participants can no longer be resolved.');
        }

        if (! $this->allowsImpersonatedRequest($request, $actor, $target, $state)) {
            return $this->conditionDenied($request, $actor, $target, $state);
        }

        $request->attributes->set('kinship.impersonation', $state);

        return $next($request);
    }

    protected function resolveVerifiedState(Request $request): ?ImpersonationState
    {
        return $this->tokens->resolve($request);
    }

    protected function allowsImpersonatedRequest(
        Request $request,
        Authenticatable $actor,
        Authenticatable $target,
        ImpersonationState $state,
    ): bool {
        return true;
    }

    protected function conditionDenied(
        Request $request,
        Authenticatable $actor,
        Authenticatable $target,
        ImpersonationState $state,
    ): mixed {
        return $this->invalid($request, 403, 'The application no longer permits this impersonation grant.');
    }

    protected function invalid(Request $request, int $status, string $message): mixed
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'status' => false,
                'message' => $message,
            ], $status);
        }

        abort($status, $message);
    }
}
