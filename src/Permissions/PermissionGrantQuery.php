<?php

namespace Tetranyble\Kinship\Permissions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use RuntimeException;
use Tetranyble\Kinship\Contracts\PermissionGrantSource;
use Tetranyble\Kinship\Support\AuthorizationContext;

final class PermissionGrantQuery
{
    public function __construct(private readonly Container $container) {}

    /** @return list<string> */
    public function names(Model $subject, AuthorizationContext $context): array
    {
        $query = null;

        foreach ((array) config('kinship.permission_sources', []) as $sourceClass) {
            if (! is_string($sourceClass) || ! is_a($sourceClass, PermissionGrantSource::class, true)) {
                throw new RuntimeException('Each Kinship permission source must implement '.PermissionGrantSource::class.'.');
            }

            $source = $this->container->make($sourceClass);
            if (! $source instanceof PermissionGrantSource) {
                throw new RuntimeException("Kinship permission source [{$sourceClass}] resolved an invalid instance.");
            }

            $sourceQuery = $source->query($subject, $context);
            if ($sourceQuery === null) {
                continue;
            }

            if ($query !== null && $query->getConnection() !== $sourceQuery->getConnection()) {
                throw new RuntimeException('Kinship permission sources must use the same database connection.');
            }

            $query = $query === null ? $sourceQuery : $query->union($sourceQuery);
        }

        if (! $query instanceof Builder) {
            return [];
        }

        return $query->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->map(fn (mixed $name): string => (string) $name)
            ->unique()
            ->values()
            ->all();
    }
}
