<?php

namespace App\Services\SpellHistory;

use InvalidArgumentException;
use RuntimeException;

final class SafePath
{
    public static function existingDirectory(string $path, string $label): string
    {
        self::assertAbsolute($path, $label);

        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException("{$label} must be an existing directory: {$path}");
        }

        self::assertNotFilesystemRoot($resolved, $label);

        return self::normalize($resolved);
    }

    public static function createDirectory(string $path, string $label): string
    {
        self::assertAbsolute($path, $label);

        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create {$label}: {$path}");
        }

        return self::existingDirectory($path, $label);
    }

    public static function prospectiveDirectory(string $path, string $label): string
    {
        self::assertAbsolute($path, $label);

        if (file_exists($path)) {
            return self::existingDirectory($path, $label);
        }

        $segments = [];
        $ancestor = $path;
        while (! file_exists($ancestor)) {
            $segment = basename($ancestor);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException("{$label} contains an unsafe path segment.");
            }
            array_unshift($segments, $segment);

            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                throw new InvalidArgumentException("{$label} has no existing parent directory.");
            }
            $ancestor = $parent;
        }

        $resolved = self::existingDirectory($ancestor, "{$label} parent");
        $prospective = $resolved.'/'.implode('/', $segments);
        self::assertNotFilesystemRoot($prospective, $label);

        return self::normalize($prospective);
    }

    public static function assertContained(string $path, string $root, string $label): string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException("{$label} does not exist: {$path}");
        }

        $resolved = self::normalize($resolved);
        $root = self::normalize($root);

        if ($resolved !== $root && ! str_starts_with(self::comparisonKey($resolved), self::comparisonKey($root).'/')) {
            throw new RuntimeException("{$label} resolves outside its allowed root.");
        }

        return $resolved;
    }

    public static function pathsOverlap(string $left, string $right): bool
    {
        $left = rtrim(self::comparisonKey(self::normalize($left)), '/');
        $right = rtrim(self::comparisonKey(self::normalize($right)), '/');

        return $left === $right
            || str_starts_with($left.'/', $right.'/')
            || str_starts_with($right.'/', $left.'/');
    }

    public static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function assertAbsolute(string $path, string $label): void
    {
        $isUnixAbsolute = str_starts_with($path, '/');
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $isUncAbsolute = str_starts_with($path, '\\\\');

        if (! $isUnixAbsolute && ! $isWindowsAbsolute && ! $isUncAbsolute) {
            throw new InvalidArgumentException("{$label} must be an absolute path.");
        }
    }

    private static function assertNotFilesystemRoot(string $path, string $label): void
    {
        $normalized = self::normalize($path);
        if ($normalized === '' || $normalized === '/' || preg_match('/^[A-Za-z]:$/', $normalized) === 1) {
            throw new InvalidArgumentException("{$label} cannot be a filesystem root.");
        }
    }

    private static function comparisonKey(string $path): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
