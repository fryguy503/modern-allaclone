<?php

namespace Tests\Support;

use App\Services\SpellHistory\SpellCanonicalizer;
use App\Services\SpellHistory\SpellHistoryArtifact;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait CreatesSpellHistoryPackageDataset
{
    /** @return array{dataset_root: string, spell_relative: string, spell_bytes: int, manifest_bytes: int, completion_bytes: int} */
    private function createPackageDataset(string $artifactRoot, string $datasetKey): array
    {
        $datasetRoot = $artifactRoot.'/datasets/'.$datasetKey;
        $spellRelative = SpellHistoryArtifact::spellRelativePath(447);
        mkdir($datasetRoot.'/'.dirname($spellRelative), 0755, true);

        $spell = [
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'spell',
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'dataset' => $datasetKey,
            'spell_id' => 447,
            'first_observed_at' => '2025-12-03T05:26:16',
            'last_observed_at' => '2025-12-03T05:26:16',
            'present_in_latest_snapshot' => true,
            'latest_presence_status' => 'present',
            'latest_name' => 'Test Spell',
            'latest_icon' => 1,
            'absence_ranges' => [],
            'revision_count' => 1,
            'revisions' => [[
                'type' => 'first_observed',
                'snapshot' => 'spelldata_Live_2025-12-03_05_26_16',
                'previous_snapshot' => null,
                'observed_at' => '2025-12-03T05:26:16',
                'groups' => [],
            ]],
        ];
        $spellJson = $this->packageJson($spell);
        file_put_contents($datasetRoot.'/'.$spellRelative, $spellJson);

        $manifest = [
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'manifest',
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'dataset' => $datasetKey,
            'snapshots' => [[
                'sequence' => 1,
                'key' => 'spelldata_Live_2025-12-03_05_26_16',
                'observed_at' => '2025-12-03T05:26:16',
                'previous_snapshot' => null,
                'source_physical_line_count' => 2,
                'health_status' => 'healthy',
                'trusted_for_absence_confirmation' => true,
            ]],
            'stats' => [
                'snapshot_count' => 1,
                'spell_count' => 1,
                'revision_count' => 1,
                'artifact_bytes' => 0,
            ],
        ];
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $manifestJson = $this->packageJson($manifest);
            $artifactBytes = strlen($manifestJson) + strlen($spellJson);
            if ($manifest['stats']['artifact_bytes'] === $artifactBytes) {
                break;
            }
            $manifest['stats']['artifact_bytes'] = $artifactBytes;
        }
        $manifestJson = $this->packageJson($manifest);
        file_put_contents($datasetRoot.'/manifest.json', $manifestJson);

        $completionJson = $this->packageJson([
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'completion',
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'dataset' => $datasetKey,
            'manifest_sha256' => hash('sha256', $manifestJson),
            'manifest_bytes' => strlen($manifestJson),
            'spell_count' => 1,
            'revision_count' => 1,
        ]);
        file_put_contents($datasetRoot.'/COMPLETE.json', $completionJson);
        file_put_contents($artifactRoot.'/CURRENT', $datasetKey."\n");

        return [
            'dataset_root' => $datasetRoot,
            'spell_relative' => $spellRelative,
            'spell_bytes' => strlen($spellJson),
            'manifest_bytes' => strlen($manifestJson),
            'completion_bytes' => strlen($completionJson),
        ];
    }

    /** @param array<string, mixed> $value */
    private function packageJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function removePackageTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isLink() || $entry->isFile()
                ? @unlink($entry->getPathname())
                : @rmdir($entry->getPathname());
        }
        @rmdir($path);
    }
}
