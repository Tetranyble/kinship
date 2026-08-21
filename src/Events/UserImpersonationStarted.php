<?php

namespace Tetranyble\Kinship\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Tetranyble\Kinship\Impersonation\ImpersonationState;

final readonly class UserImpersonationStarted
{
    public function __construct(
        public Authenticatable $actor,
        public Authenticatable $target,
        public ImpersonationState $state,
    ) {}
}
