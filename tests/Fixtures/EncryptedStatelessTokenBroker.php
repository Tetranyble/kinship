<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Tetranyble\Kinship\Contracts\StatelessImpersonationTokenBroker;
use Tetranyble\Kinship\Impersonation\ImpersonationState;
use Tetranyble\Kinship\Impersonation\StatelessImpersonationCredential;
use Throwable;

class EncryptedStatelessTokenBroker implements StatelessImpersonationTokenBroker
{
    /** @var array<string, true> */
    private array $revoked = [];

    public function issueImpersonationToken(
        Authenticatable $subject,
        ImpersonationState $grant,
    ): StatelessImpersonationCredential {
        return new StatelessImpersonationCredential(
            accessToken: Crypt::encryptString(json_encode($grant->toArray(), JSON_THROW_ON_ERROR)),
            expiresAt: $grant->expiresAt,
        );
    }

    public function resolve(Request $request): ?ImpersonationState
    {
        $credential = $request->header('X-Test-Impersonation');
        if (! is_string($credential) || $credential === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($credential), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $state = ImpersonationState::fromArray($payload);

        return $state !== null && ! isset($this->revoked[$state->id]) ? $state : null;
    }

    public function revoke(string $grantId): void
    {
        $this->revoked[$grantId] = true;
    }
}
