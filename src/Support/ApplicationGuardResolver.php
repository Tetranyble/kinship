<?php

namespace Tetranyble\Kinship\Support;

use Illuminate\Auth\AuthManager;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;
use RuntimeException;
use Tetranyble\Kinship\Contracts\GuardResolver;

final class ApplicationGuardResolver implements GuardResolver
{
    public function resolve(?Model $subject = null, ?string $requestedGuard = null): string
    {
        if ($guard = $this->nonEmpty($requestedGuard)) {
            return $guard;
        }

        if ($subject !== null && method_exists($subject, 'guardName')) {
            $method = new ReflectionMethod($subject, 'guardName');
            if ($method->isPublic() && ! $method->isStatic()) {
                $guard = $this->nonEmpty($method->invoke($subject));
                if ($guard !== null) {
                    return $guard;
                }
            }
        }

        if ($subject !== null) {
            $guard = $this->nonEmpty($subject->getAttribute('guard_name'));
            if ($guard !== null) {
                return $guard;
            }
        }

        $configured = config('kinship.guard');
        if ($configured !== null) {
            $guard = $this->nonEmpty($configured);
            if ($guard === null) {
                throw new RuntimeException('Kinship guard must be null or a non-empty string.');
            }

            return $guard;
        }

        $auth = app('auth');
        if ($auth instanceof AuthManager) {
            $guard = $this->nonEmpty($auth->getDefaultDriver());
            if ($guard !== null) {
                return $guard;
            }
        }

        return $this->nonEmpty(config('auth.defaults.guard')) ?? 'web';
    }

    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
