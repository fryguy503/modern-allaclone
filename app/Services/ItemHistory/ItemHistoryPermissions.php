<?php

namespace App\Services\ItemHistory;

use App\Services\SpellHistory\SafePath;
use Closure;
use RuntimeException;

final class ItemHistoryPermissions
{
    private const DIRECTORY_MODE = 0755;

    private const FILE_MODE = 0644;

    /** @var Closure(string, int): bool */
    private readonly Closure $chmodPath;

    /** @var Closure(string): int|false */
    private readonly Closure $pathPermissions;

    public function __construct(
        ?callable $chmodPath = null,
        ?callable $pathPermissions = null,
    ) {
        $this->chmodPath = $chmodPath !== null
            ? Closure::fromCallable($chmodPath)
            : static fn (string $path, int $mode): bool => @chmod($path, $mode);
        $this->pathPermissions = $pathPermissions !== null
            ? Closure::fromCallable($pathPermissions)
            : static fn (string $path): int|false => @fileperms($path);
    }

    public function normalizeDirectory(string $path, string $root, string $label): void
    {
        $this->normalize($path, $root, true, self::DIRECTORY_MODE, $label);
    }

    public function normalizeFile(string $path, string $root, string $label): void
    {
        $this->normalize($path, $root, false, self::FILE_MODE, $label);
    }

    private function normalize(
        string $path,
        string $root,
        bool $directory,
        int $mode,
        string $label,
    ): void {
        if (is_link($path) || ($directory ? ! is_dir($path) : ! is_file($path))) {
            throw new RuntimeException("A verified {$label} became unsafe before activation.");
        }
        $resolved = SafePath::assertContained($path, $root, ucfirst($label));
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $changed = ($this->chmodPath)($resolved, $mode);
        if ($changed !== true) {
            throw new RuntimeException("Unable to make the verified {$label} readable by the web application.");
        }
        clearstatcache(true, $resolved);
        $actual = ($this->pathPermissions)($resolved);
        if (! is_int($actual)) {
            throw new RuntimeException("The verified {$label} did not retain its required permissions.");
        }
        $actual &= 0777;
        if ($actual === $mode) {
            return;
        }

        // Windows-backed bind mounts can expose synthetic 0777 modes to Linux
        // and report chmod success without changing them. Permit that no-op only
        // when the reported mode already grants the read/traverse access needed
        // by a separately running web process.
        $requiredAccess = $directory ? 0555 : 0444;
        if (($actual & $requiredAccess) !== $requiredAccess) {
            throw new RuntimeException("The verified {$label} did not retain its required permissions.");
        }
    }
}
