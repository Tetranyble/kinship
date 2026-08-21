<?php

namespace Tetranyble\Kinship\Impersonation;

final readonly class ImpersonationState
{
    public function __construct(
        public string $id,
        public string $actorType,
        public int|string $actorId,
        public string $targetType,
        public int|string $targetId,
        public string $guard,
        public string $reason,
        public int $startedAt,
        public int $expiresAt,
    ) {}

    /** @return array{id: string, actor_type: string, actor_id: int|string, target_type: string, target_id: int|string, guard: string, reason: string, started_at: int, expires_at: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'guard' => $this->guard,
            'reason' => $this->reason,
            'started_at' => $this->startedAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)
            || ! is_string($value['id'] ?? null)
            || $value['id'] === ''
            || ! is_string($value['actor_type'] ?? null)
            || ! self::validIdentifier($value['actor_id'] ?? null)
            || ! is_string($value['target_type'] ?? null)
            || ! self::validIdentifier($value['target_id'] ?? null)
            || ! is_string($value['guard'] ?? null)
            || ! is_string($value['reason'] ?? null)
            || ! is_int($value['started_at'] ?? null)
            || ! is_int($value['expires_at'] ?? null)) {
            return null;
        }

        return new self(
            id: $value['id'],
            actorType: $value['actor_type'],
            actorId: $value['actor_id'],
            targetType: $value['target_type'],
            targetId: $value['target_id'],
            guard: $value['guard'],
            reason: $value['reason'],
            startedAt: $value['started_at'],
            expiresAt: $value['expires_at'],
        );
    }

    public function expired(?int $now = null): bool
    {
        return ($now ?? time()) >= $this->expiresAt;
    }

    private static function validIdentifier(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && $value !== '');
    }
}
