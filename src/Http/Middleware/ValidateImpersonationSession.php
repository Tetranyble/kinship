<?php

namespace Tetranyble\Kinship\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Tetranyble\Kinship\Exceptions\ImpersonationException;
use Tetranyble\Kinship\Impersonation\ImpersonationManager;
use Tetranyble\Kinship\Impersonation\ImpersonationState;

class ValidateImpersonationSession
{
    public function __construct(private readonly ImpersonationManager $impersonation) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $state = $this->impersonation->state();
        if ($state === null) {
            if ($this->impersonation->hasStatePayload()) {
                $this->impersonation->discardInvalidState();

                return $this->invalid($request, 403, 'The impersonation session is invalid.');
            }

            return $next($request);
        }

        if (! $this->impersonation->enabled()) {
            try {
                $this->impersonation->stop();
            } catch (ImpersonationException) {
                $this->impersonation->discardInvalidState();
            }

            return $this->invalid($request, 403, 'User impersonation is disabled.');
        }

        if (! $this->impersonation->currentUserMatchesTarget($state)) {
            $this->impersonation->abandon();

            return $this->invalid($request, 403, 'The impersonation session no longer matches the authenticated user.');
        }

        if ($state->expired()) {
            try {
                $this->impersonation->stop();
            } catch (ImpersonationException) {
                $this->impersonation->abandon();
            }

            return $this->invalid($request, 419, 'The impersonation session has expired.');
        }

        $actor = $this->impersonation->actor();
        $target = $this->impersonation->target();
        if ($actor === null || $target === null) {
            try {
                $this->impersonation->stop();
            } catch (ImpersonationException) {
                $this->impersonation->abandon();
            }

            return $this->invalid($request, 403, 'The impersonation participants can no longer be resolved.');
        }

        if (! $this->allowsImpersonatedRequest($request, $actor, $target, $state)) {
            try {
                $this->impersonation->stop();
            } catch (ImpersonationException) {
                $this->impersonation->abandon();
            }

            return $this->conditionDenied($request, $actor, $target, $state);
        }

        return $next($request);
    }

    /**
     * Extend this hook for application-owned conditions such as recent MFA,
     * account status, support shifts, compliance holds, or route restrictions.
     */
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
        return $this->invalid($request, 403, 'The application no longer permits this impersonation session.');
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
