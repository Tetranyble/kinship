<?php

namespace Tetranyble\Kinship\Http\Middleware;

use Illuminate\Http\Request;

trait ResolvesAuthenticatedUser
{
    protected function resolveAuthenticatedUser(Request $request): mixed
    {
        // Authentication belongs to the host application. Kinship authorizes only
        // the principal already established for this request and never scans other
        // guards for an alternative identity.
        return $request->user();
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
