<?php

namespace Tetranyble\Kinship\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

final class ModelResourceDiscovery
{
    /** @return list<string> */
    public function resources(): array
    {
        if (! (bool) config('kinship.catalog.discovery.enabled', false)) {
            return [];
        }

        $configuredPath = config('kinship.catalog.discovery.path', 'Models');
        if (! is_string($configuredPath) || trim($configuredPath) === '') {
            throw new RuntimeException('Kinship catalog discovery path must be a non-empty string.');
        }

        $configuredPath = trim($configuredPath);
        $path = $this->absolutePath($configuredPath);
        if (! is_dir($path)) {
            throw new RuntimeException("Kinship catalog discovery directory [{$path}] does not exist.");
        }

        $namespace = $this->namespace($configuredPath);
        $resources = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen(rtrim($path, DIRECTORY_SEPARATOR)) + 1);
            $relativeClass = substr($relativePath, 0, -4);
            $class = $namespace.'\\'.str_replace(['/', '\\'], '\\', $relativeClass);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $resources[] = Str::snake($reflection->getShortName());
        }

        sort($resources);

        return array_values(array_unique($resources));
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        if ($path === 'app' || str_starts_with($path, 'app/')) {
            return base_path($path);
        }

        return app_path($path);
    }

    private function namespace(string $path): string
    {
        $configuredNamespace = config('kinship.catalog.discovery.namespace');
        if (is_string($configuredNamespace) && trim($configuredNamespace) !== '') {
            return trim($configuredNamespace, " \\t\n\r\0\x0B\\");
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Kinship catalog discovery requires a namespace when path is absolute.');
        }

        $relative = $path === 'app'
            ? ''
            : preg_replace('#^app/#', '', trim($path, '/'));
        $suffix = is_string($relative) && $relative !== ''
            ? '\\'.str_replace('/', '\\', $relative)
            : '';

        return rtrim(app()->getNamespace(), '\\').$suffix;
    }
}
