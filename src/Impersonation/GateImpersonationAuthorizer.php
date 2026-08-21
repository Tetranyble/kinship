<?php

namespace Tetranyble\Kinship\Impersonation;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;
use Tetranyble\Kinship\Contracts\ImpersonationAuthorizer;

final class GateImpersonationAuthorizer implements ImpersonationAuthorizer
{
    public function __construct(private readonly Gate $gate) {}

    public function authorize(Authenticatable $actor, Authenticatable $target, string $guard): bool
    {
        $ability = config('kinship.impersonation.ability', 'kinship.impersonate');

        if (! is_string($ability) || trim($ability) === '') {
            throw new RuntimeException('Kinship impersonation ability must be a non-empty string.');
        }

        return $this->gate->forUser($actor)->allows($ability, [$target, $guard]);
    }
}
