<?php

namespace Tetranyble\Kinship\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    use ResolvesAuthenticatedUser;

    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $this->resolveAuthenticatedUser($request);

        if ($user && method_exists($user, 'hasRoles') && $user->hasRoles($roles)) {
            return $next($request);
        }

        return $this->unauthorized($request);
    }
}
