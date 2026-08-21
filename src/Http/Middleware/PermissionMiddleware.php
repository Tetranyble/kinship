<?php

namespace Tetranyble\Kinship\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PermissionMiddleware
{
    use ResolvesAuthenticatedUser;

    public function handle(Request $request, Closure $next, string ...$permissions): mixed
    {
        $user = $this->resolveAuthenticatedUser($request);

        if ($user && method_exists($user, 'hasPermissions') && $user->hasPermissions($permissions)) {
            return $next($request);
        }

        return $this->unauthorized($request);
    }
}
