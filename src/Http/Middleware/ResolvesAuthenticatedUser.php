<?php

namespace Tetranyble\Kinship\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

trait ResolvesAuthenticatedUser
{
    protected function resolveAuthenticatedUser(Request $request): mixed
    {
        if ($user = $request->user()) {
            return $user;
        }

        $route = $request->route();
        $routeMiddleware = $route instanceof Route ? $route->gatherMiddleware() : [];
        $preferredGuards = collect($routeMiddleware)
            ->filter(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'auth:'))
            ->flatMap(function (string $middleware): array {
                $guards = explode(':', $middleware, 2)[1] ?? '';

                return array_values(array_filter(array_map('trim', explode(',', $guards))));
            });

        return $preferredGuards
            ->merge(array_keys((array) config('auth.guards', [])))
            ->unique()
            ->map(fn (string $guard): mixed => auth($guard)->user())
            ->first(fn (mixed $user): bool => $user !== null);
    }

    protected function unauthorized(Request $request): mixed
    {
        $message = (string) config('kinship.middleware.unauthorized_message', 'This action is unauthorized.');

        if ($request->expectsJson() || $request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => false,
                'message' => $message,
            ], 403);
        }

        abort(403, $message);
    }
}
