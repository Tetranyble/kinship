<?php

namespace Tetranyble\Kinship\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Tetranyble\Kinship\Impersonation\ImpersonationState;
use Tetranyble\Kinship\Impersonation\StatelessImpersonationCredential;

interface StatelessImpersonationTokenBroker
{
    public function issueImpersonationToken(
        Authenticatable $subject,
        ImpersonationState $grant,
    ): StatelessImpersonationCredential;

    /**
     * Return state only after validating the credential's signature, issuer,
     * audience, token type, expiry, and revocation status.
     */
    public function resolve(Request $request): ?ImpersonationState;

    public function revoke(string $grantId): void;
}
