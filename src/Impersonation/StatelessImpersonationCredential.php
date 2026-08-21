<?php

namespace Tetranyble\Kinship\Impersonation;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/** @implements Arrayable<string, int|string> */
final readonly class StatelessImpersonationCredential implements Arrayable
{
    public function __construct(
        public string $accessToken,
        public int $expiresAt,
        public string $tokenType = 'Bearer',
    ) {
        if (trim($this->accessToken) === '') {
            throw new InvalidArgumentException('A stateless impersonation access token cannot be empty.');
        }

        if (trim($this->tokenType) === '') {
            throw new InvalidArgumentException('A stateless impersonation token type cannot be empty.');
        }

        if ($this->expiresAt < 1) {
            throw new InvalidArgumentException('A stateless impersonation expiry must be a positive Unix timestamp.');
        }
    }

    /** @return array{access_token: string, token_type: string, expires_at: int} */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_at' => $this->expiresAt,
        ];
    }
}
