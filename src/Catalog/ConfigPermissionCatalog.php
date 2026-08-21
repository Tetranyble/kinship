<?php

namespace Tetranyble\Kinship\Catalog;

use Illuminate\Support\Str;
use RuntimeException;
use Tetranyble\Kinship\Contracts\PermissionCatalog;

final class ConfigPermissionCatalog implements PermissionCatalog
{
    public function __construct(private readonly ModelResourceDiscovery $discovery) {}

    public function permissions(): array
    {
        $abilities = $this->abilities();
        $definitions = [];
        $resources = array_merge(
            $this->discovery->resources(),
            (array) config('kinship.catalog.resources', []),
        );

        foreach ($resources as $key => $metadata) {
            [$resource, $resourceLabel, $resourceAbilities] = $this->resource($key, $metadata, $abilities);
            $separator = $this->requiredString(config('kinship.catalog.separator', '.'), 'catalog separator');

            foreach ($resourceAbilities as $ability => $abilityLabel) {
                $name = $resource.$separator.$ability;
                $definitions[$name] = new PermissionDefinition(
                    $name,
                    trim($resourceLabel.' '.$abilityLabel),
                    $resource,
                );
            }
        }

        foreach ((array) config('kinship.catalog.permissions', []) as $key => $entry) {
            [$name, $label, $group] = $this->permission($key, $entry);
            $definitions[$name] = new PermissionDefinition(
                $name,
                $label ?? Str::headline(str_replace(['.', ':'], ' ', $name)),
                $group ?? $this->inferredGroup($name),
            );
        }

        return array_values($definitions);
    }

    public function roles(): array
    {
        $definitions = [];

        foreach ((array) config('kinship.catalog.roles', []) as $key => $metadata) {
            if (! is_array($metadata)) {
                throw new RuntimeException('Each Kinship catalog role must be an array.');
            }

            $rawName = is_string($key) ? $key : ($metadata['name'] ?? null);
            $name = Str::slug($this->requiredString($rawName, 'catalog role name'));
            $patterns = array_values(array_unique(array_map(
                fn (mixed $pattern): string => $this->requiredString($pattern, "permission pattern for role [{$name}]"),
                (array) ($metadata['permissions'] ?? []),
            )));

            $definitions[$name] = new RoleDefinition(
                $name,
                $this->optionalString($metadata['label'] ?? null) ?? Str::headline($name),
                $this->optionalString($metadata['description'] ?? null),
                (int) ($metadata['order'] ?? 0),
                (bool) ($metadata['is_system'] ?? true),
                $patterns,
            );
        }

        return array_values($definitions);
    }

    /** @return array<string, string> */
    private function abilities(): array
    {
        $abilities = [];

        foreach ((array) config('kinship.catalog.abilities', []) as $key => $label) {
            $name = is_string($key) ? $key : $label;
            $name = $this->requiredString($name, 'catalog ability');
            $abilities[$name] = is_string($key)
                ? ($this->optionalString($label) ?? Str::headline($name))
                : Str::headline($name);
        }

        return $abilities;
    }

    /**
     * @param  array<string, string>  $defaultAbilities
     * @return array{string, string, array<string, string>}
     */
    private function resource(int|string $key, mixed $metadata, array $defaultAbilities): array
    {
        if (is_string($metadata)) {
            $resource = $metadata;
            $metadata = [];
        } elseif (is_string($key) && is_array($metadata)) {
            $resource = $key;
        } else {
            throw new RuntimeException('Each Kinship catalog resource must be a string or keyed configuration array.');
        }

        $resource = $this->requiredString($resource, 'catalog resource');
        $label = $this->optionalString($metadata['label'] ?? null) ?? Str::headline($resource);
        $selected = $metadata['abilities'] ?? null;

        if ($selected === null) {
            return [$resource, $label, $defaultAbilities];
        }

        $abilities = [];
        foreach ((array) $selected as $ability) {
            $ability = $this->requiredString($ability, "ability for resource [{$resource}]");

            if (! array_key_exists($ability, $defaultAbilities)) {
                throw new RuntimeException("Unknown Kinship catalog ability [{$ability}] for resource [{$resource}].");
            }

            $abilities[$ability] = $defaultAbilities[$ability];
        }

        return [$resource, $label, $abilities];
    }

    /** @return array{string, ?string, ?string} */
    private function permission(int|string $key, mixed $entry): array
    {
        if (is_string($entry)) {
            if (is_string($key)) {
                return [
                    $this->requiredString($key, 'catalog permission name'),
                    $this->optionalString($entry),
                    null,
                ];
            }

            return [$this->requiredString($entry, 'catalog permission name'), null, null];
        }

        if (! is_array($entry)) {
            throw new RuntimeException('Each Kinship catalog permission must be a name or configuration array.');
        }

        $name = is_string($key) ? $key : ($entry['name'] ?? null);

        return [
            $this->requiredString($name, 'catalog permission name'),
            $this->optionalString($entry['label'] ?? null),
            $this->optionalString($entry['group'] ?? null),
        ];
    }

    private function inferredGroup(string $name): string
    {
        $parts = preg_split('/[.:]/', $name, 2);

        return $parts[0] ?? $name;
    }

    private function requiredString(mixed $value, string $description): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Kinship {$description} must be a non-empty string.");
        }

        return trim($value);
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
