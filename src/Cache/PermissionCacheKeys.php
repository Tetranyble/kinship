<?php

namespace Tetranyble\Kinship\Cache;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tetranyble\Kinship\Support\AuthorizationContext;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class PermissionCacheKeys
{
    public function __construct(private readonly WorkspaceConfiguration $workspaces) {}

    public function guardVersion(string $guard): string
    {
        return $this->prefix().':version:guard:'.$this->digest($guard);
    }

    public function scopeVersion(string $guard, string $scope): string
    {
        return $this->prefix().':version:scope:'.$this->digest($guard."\0".$scope);
    }

    public function subjectVersion(Model $subject, string $guard): string
    {
        return $this->prefix().':version:subject:'.$this->digest($this->subjectIdentity($subject)."\0".$guard);
    }

    /** @param array<string, string> $versions */
    public function permissions(Model $subject, AuthorizationContext $context, array $versions): string
    {
        $scope = $context->workspaceScope($this->workspaces);
        if ($scope === null) {
            throw new RuntimeException('Kinship cannot cache an unresolved workspace authorization context.');
        }

        ksort($versions);

        return $this->prefix().':permissions:'.$this->digest(implode("\0", [
            $this->subjectIdentity($subject),
            $context->fingerprint($this->workspaces),
            ...array_values($versions),
        ]));
    }

    private function subjectIdentity(Model $subject): string
    {
        $key = $subject->getKey();
        if (! is_int($key) && ! is_string($key)) {
            throw new RuntimeException('Kinship cannot cache permissions for an unpersisted subject.');
        }

        return $subject->getMorphClass().'#'.$key;
    }

    private function prefix(): string
    {
        $prefix = config('kinship.cache.prefix', 'kinship');

        if (! is_string($prefix) || trim($prefix) === '') {
            throw new RuntimeException('Kinship cache.prefix must be a non-empty string.');
        }

        return trim($prefix);
    }

    private function digest(string $value): string
    {
        return hash('sha256', $value);
    }
}
