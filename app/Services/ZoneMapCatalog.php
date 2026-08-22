<?php

namespace App\Services;

use Illuminate\Contracts\Routing\UrlGenerator;
use JsonException;

class ZoneMapCatalog
{
    private const MAX_MANIFEST_BYTES = 5 * 1024 * 1024;

    private const MAX_ASSET_BYTES = 8 * 1024 * 1024;

    private const MAX_SEGMENTS = 1_000_000;

    private const MAX_POINTS = 250_000;

    private const MAX_ANNOTATIONS = 256;

    private const MAX_ANNOTATION_LABEL_BYTES = 240;

    private const MAX_ANNOTATION_LABEL_CHARACTERS = 160;

    private const MAX_ANNOTATION_COORDINATE = 1_000_000;

    private const ANNOTATION_BOUNDS_MARGIN_RATIO = 0.25;

    private const MIN_ANNOTATION_BOUNDS_MARGIN = 64.0;

    private ?array $zones = null;

    private string $manifestPath;

    private string $publicRoot;

    public function __construct(
        private readonly UrlGenerator $url,
        ?string $manifestPath = null,
        ?string $publicRoot = null,
    ) {
        $this->manifestPath = $manifestPath ?? public_path('maps/manifest.json');
        $this->publicRoot = $publicRoot ?? public_path();
    }

    /**
     * Return normalized metadata for an allowlisted zone map.
     */
    public function find(string $shortName): ?array
    {
        if (! preg_match('/^[a-z0-9]+$/', $shortName)) {
            return null;
        }

        $defaultEntry = $this->entries()[$shortName] ?? null;
        if (! is_array($defaultEntry)) {
            return null;
        }

        if ($this->prefersLegacy($shortName) && is_array($defaultEntry['legacy'] ?? null)) {
            $legacy = $this->normalizeEntry($shortName, $defaultEntry['legacy'], 'legacy');
            if ($legacy !== null) {
                return $legacy;
            }
        }

        return $this->normalizeEntry($shortName, $defaultEntry, 'default');
    }

    private function normalizeEntry(string $shortName, array $entry, string $variant): ?array
    {
        $relativePath = $this->relativeAssetPath(
            $shortName,
            $entry['path'] ?? null,
            $entry['sha256'] ?? null,
            $variant,
        );
        if ($relativePath === null || ! $this->assetIsInsidePublicRoot($relativePath)) {
            return null;
        }

        $bytes = $this->nonNegativeInteger($entry['bytes'] ?? null, self::MAX_ASSET_BYTES);
        $segments = $this->nonNegativeInteger($entry['segments'] ?? null, self::MAX_SEGMENTS);
        $points = $this->nonNegativeInteger($entry['points'] ?? null, self::MAX_POINTS);
        $bounds = $this->bounds($entry['bounds'] ?? null);
        $annotations = $bounds === null
            ? null
            : $this->annotations($entry['annotations'] ?? [], $bounds);

        if ($bytes === null
            || $bytes !== filesize($this->publicRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath))
            || $segments === null
            || $points === null
            || $bounds === null
            || $annotations === null) {
            return null;
        }

        return [
            'available' => true,
            'variant' => $variant,
            'url' => $this->url->asset($relativePath),
            'segments' => $segments,
            'points' => $points,
            'bounds' => $bounds,
            'annotations' => $annotations,
        ];
    }

    private function prefersLegacy(string $shortName): bool
    {
        $legacyZones = config('everquest.maps.legacy_zones', []);

        return is_array($legacyZones) && in_array($shortName, $legacyZones, true);
    }

    private function entries(): array
    {
        if ($this->zones !== null) {
            return $this->zones;
        }

        $this->zones = [];

        if (! is_file($this->manifestPath) || ! is_readable($this->manifestPath)) {
            return $this->zones;
        }

        $size = filesize($this->manifestPath);
        if ($size === false || $size <= 0 || $size > self::MAX_MANIFEST_BYTES) {
            return $this->zones;
        }

        $contents = @file_get_contents($this->manifestPath);
        if ($contents === false) {
            return $this->zones;
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->zones;
        }

        if (! is_array($manifest)
            || ($manifest['version'] ?? null) !== 1
            || ($manifest['format'] ?? null) !== 'EQM1'
            || ! is_array($manifest['zones'] ?? null)) {
            return $this->zones;
        }

        foreach ($manifest['zones'] as $shortName => $entry) {
            if (is_string($shortName)
                && preg_match('/^[a-z0-9]+$/', $shortName)
                && is_array($entry)) {
                $this->zones[$shortName] = $entry;
            }
        }

        return $this->zones;
    }

    private function relativeAssetPath(string $shortName, mixed $path, mixed $sha256, string $variant): ?string
    {
        if (! is_string($path)
            || ! is_string($sha256)
            || ! preg_match('/^[a-f0-9]{64}$/', $sha256)
            || ! preg_match('/^maps\/zones\/([a-z0-9]+)(\.legacy)?\.([a-f0-9]{12})\.eqmap$/', $path, $matches)
            || $matches[1] !== $shortName
            || (($matches[2] ?? '') === '.legacy' ? 'legacy' : 'default') !== $variant
            || $matches[3] !== substr($sha256, 0, 12)) {
            return null;
        }

        return $path;
    }

    private function assetIsInsidePublicRoot(string $relativePath): bool
    {
        $publicRoot = realpath($this->publicRoot);
        $mapRoot = realpath($this->publicRoot.DIRECTORY_SEPARATOR.'maps'.DIRECTORY_SEPARATOR.'zones');
        $asset = realpath($this->publicRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($publicRoot === false || $mapRoot === false || $asset === false || ! is_file($asset)) {
            return false;
        }

        $separator = DIRECTORY_SEPARATOR;
        $normalizedPublicRoot = rtrim($publicRoot, '\\/').$separator;
        $normalizedMapRoot = rtrim($mapRoot, '\\/').$separator;

        return str_starts_with($mapRoot.$separator, $normalizedPublicRoot)
            && str_starts_with($asset, $normalizedMapRoot);
    }

    private function nonNegativeInteger(mixed $value, int $maximum): ?int
    {
        if (! is_int($value) || $value < 0 || $value > $maximum) {
            return null;
        }

        return $value;
    }

    private function bounds(mixed $bounds): ?array
    {
        if (! is_array($bounds)) {
            return null;
        }

        $keys = ['min_x', 'min_y', 'min_z', 'max_x', 'max_y', 'max_z'];
        $normalized = [];

        foreach ($keys as $key) {
            $value = $bounds[$key] ?? null;
            if (! is_int($value) && ! is_float($value)) {
                return null;
            }

            $value = (float) $value;
            if (! is_finite($value)) {
                return null;
            }

            $normalized[$key] = $value;
        }

        if ($normalized['min_x'] > $normalized['max_x']
            || $normalized['min_y'] > $normalized['max_y']
            || $normalized['min_z'] > $normalized['max_z']) {
            return null;
        }

        return $normalized;
    }

    /**
     * Normalize display-only Brewall P annotations. Coordinates remain in
     * Brewall map space and are intentionally separate from EQEmu DB positions.
     *
     * @return array<int, array{
     *     kind: 'zone-line'|'portal',
     *     label: string,
     *     position: array{x: float, y: float, z: float}
     * }>|null
     */
    private function annotations(mixed $annotations, array $bounds): ?array
    {
        if (! is_array($annotations)
            || ! array_is_list($annotations)
            || count($annotations) > self::MAX_ANNOTATIONS) {
            return null;
        }

        $normalized = [];
        foreach ($annotations as $annotation) {
            if (! is_array($annotation)
                || count($annotation) !== 3
                || array_diff(array_keys($annotation), ['kind', 'label', 'position']) !== []
                || array_diff(['kind', 'label', 'position'], array_keys($annotation)) !== []) {
                return null;
            }

            $kind = $annotation['kind'];
            $label = $annotation['label'];
            $position = $annotation['position'];
            if (! is_string($kind)
                || ! in_array($kind, ['zone-line', 'portal'], true)
                || ! is_string($label)
                || $label === ''
                || $label !== trim($label)
                || strlen($label) > self::MAX_ANNOTATION_LABEL_BYTES
                || preg_match('/^.{1,'.self::MAX_ANNOTATION_LABEL_CHARACTERS.'}$/us', $label) !== 1
                || preg_match('/[\p{Cc}\p{Cf}\p{Cs}]/u', $label) === 1
                || preg_match('/\s{2,}/u', $label) === 1
                || ! is_array($position)
                || count($position) !== 3
                || array_diff(array_keys($position), ['x', 'y', 'z']) !== []
                || array_diff(['x', 'y', 'z'], array_keys($position)) !== []) {
                return null;
            }

            $normalizedPosition = [];
            foreach (['x', 'y', 'z'] as $axis) {
                $value = $position[$axis];
                if ((! is_int($value) && ! is_float($value))
                    || ! is_finite((float) $value)
                    || abs((float) $value) > self::MAX_ANNOTATION_COORDINATE) {
                    return null;
                }
                $normalizedPosition[$axis] = (float) $value === 0.0 ? 0.0 : (float) $value;
            }

            if (! $this->annotationWithinBounds($normalizedPosition, $bounds)) {
                return null;
            }

            $normalized[] = [
                'kind' => $kind,
                'label' => $label,
                'position' => $normalizedPosition,
            ];
        }

        return $normalized;
    }

    /**
     * Permit authored transition tags just outside geometry while rejecting
     * overlays that use a different planar coordinate system.
     *
     * @param  array{x: float, y: float, z: float}  $position
     * @param  array{min_x: float, min_y: float, min_z: float, max_x: float, max_y: float, max_z: float}  $bounds
     */
    private function annotationWithinBounds(array $position, array $bounds): bool
    {
        $spanX = max(0.0, $bounds['max_x'] - $bounds['min_x']);
        $spanY = max(0.0, $bounds['max_y'] - $bounds['min_y']);
        $marginX = max(self::MIN_ANNOTATION_BOUNDS_MARGIN, $spanX * self::ANNOTATION_BOUNDS_MARGIN_RATIO);
        $marginY = max(self::MIN_ANNOTATION_BOUNDS_MARGIN, $spanY * self::ANNOTATION_BOUNDS_MARGIN_RATIO);

        return $position['x'] >= $bounds['min_x'] - $marginX
            && $position['x'] <= $bounds['max_x'] + $marginX
            && $position['y'] >= $bounds['min_y'] - $marginY
            && $position['y'] <= $bounds['max_y'] + $marginY;
    }
}
