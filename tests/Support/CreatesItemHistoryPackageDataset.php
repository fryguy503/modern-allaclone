<?php

namespace Tests\Support;

use App\Services\ItemHistory\ItemHistoryArtifact;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait CreatesItemHistoryPackageDataset
{
    /**
     * @param  list<int>  $itemIds
     * @return array<int, string>
     */
    private function createLegacyItemHistoryArtifacts(string $artifactRoot, array $itemIds = [20_542]): array
    {
        $paths = [];
        foreach ($itemIds as $itemId) {
            $path = $artifactRoot.'/'.ItemHistoryArtifact::itemRelativePath($itemId);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $this->itemHistoryPackageJson($this->itemHistoryPackageArtifact($itemId)));
            $paths[$itemId] = $path;
        }
        $this->createCompletedItemHistoryCrawlerWorkspace($artifactRoot, $itemIds);

        return $paths;
    }

    /** @param list<int> $itemIds */
    private function createCompletedItemHistoryCrawlerWorkspace(
        string $artifactRoot,
        array $itemIds,
        ?string $workspaceRoot = null,
        ?string $artifactRootIdentity = null,
        ?string $workspaceIdentity = null,
    ): string {
        $workspaceRoot ??= dirname($artifactRoot).'/lucy-item-history-crawl';
        $artifactRootIdentity ??= $artifactRoot;
        $workspaceIdentity ??= $workspaceRoot;
        if (file_exists($workspaceRoot) || is_link($workspaceRoot)) {
            $this->removeItemHistoryPackageTree($workspaceRoot);
        }
        mkdir($workspaceRoot.'/state', 0755, true);

        $itemIds = array_values(array_unique($itemIds));
        sort($itemIds, SORT_NUMERIC);
        $items = array_map(static fn (int $itemId): array => [
            'id' => $itemId,
            'name' => "Packaged Item {$itemId}",
            'lucylink' => "https://lucy.allakhazam.com/item.html?id={$itemId}",
        ], $itemIds);
        $timestamp = '2026-09-06T21:12:54.246Z';
        file_put_contents($workspaceRoot.'/config.json', $this->itemHistoryPackageJson([
            'schema' => 'modern-allaclone.lucy-item-crawl-config',
            'version' => 1,
            'crawler_version' => '1.2.0',
            'workspace' => $workspaceIdentity,
            'artifact_root' => $artifactRootIdentity,
            'capture_strategy' => 'reversible-delta',
        ]));
        file_put_contents($workspaceRoot.'/queue.json', $this->itemHistoryPackageJson([
            'schema' => 'modern-allaclone.lucy-item-crawl-queue',
            'version' => 1,
            'created_at' => $timestamp,
            'refreshed_at' => $timestamp,
            'source_url' => 'https://lucy.allakhazam.com/itemlist.txt.gz',
            'capture' => null,
            'source_snapshot_item_count' => count($items),
            'item_count' => count($items),
            'items' => $items,
        ]));
        file_put_contents($workspaceRoot.'/state/progress.json', $this->itemHistoryPackageJson([
            'schema' => 'modern-allaclone.lucy-item-crawl-progress',
            'version' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'total_items' => count($items),
            'primary_cursor' => count($items),
            'retry_ids' => [],
            'retry_cursor' => 0,
            'completed_items' => count($items),
            'failed_items' => 0,
            'current_item_id' => null,
            'sweep_generation' => 1,
            'refresh_item_list' => false,
        ]));
        file_put_contents($workspaceRoot.'/state/status.json', $this->itemHistoryPackageJson([
            'schema' => 'modern-allaclone.lucy-item-crawl-status',
            'version' => 1,
            'state' => 'complete',
            'phase' => 'complete',
            'lastError' => null,
            'artifactRoot' => $artifactRootIdentity,
            'updatedAt' => $timestamp,
        ]));

        return $workspaceRoot;
    }

    /** @return array<string, mixed> */
    private function itemHistoryPackageArtifact(int $itemId): array
    {
        $historyHash = str_repeat('d', 64);
        $rawHash = str_repeat('e', 64);
        $name = "Packaged Item {$itemId}";

        return [
            'schema' => ItemHistoryArtifact::SCHEMA,
            'artifact_type' => 'item',
            'format_version' => ItemHistoryArtifact::REVERSIBLE_DELTA_FORMAT_VERSION,
            'parser_format_version' => ItemHistoryArtifact::REVERSIBLE_DELTA_PARSER_FORMAT_VERSION,
            'capture_strategy' => ItemHistoryArtifact::REVERSIBLE_DELTA_CAPTURE_STRATEGY,
            'item_id' => $itemId,
            'generated_at' => '2026-09-06T21:12:54.246Z',
            'latest_name' => $name,
            'latest_icon' => 512,
            'first_observed_at' => '2019-01-01T12:30:00',
            'last_observed_at' => '2020-02-02T13:45:10',
            'revision_count' => 2,
            'sources' => ['Live'],
            'complete' => true,
            'gaps' => [],
            'coverage' => [
                'history_rows' => 'captured',
                'current_raw' => 'captured',
                'historical_state' => 'reconstructed',
                'rendered_details' => 'partial',
                'direct_detail_count' => 1,
            ],
            'evidence' => [
                'history_capture_sha256s' => [$historyHash],
                'current_raw_capture_sha256' => $rawHash,
                'current_raw_source' => 'Live',
            ],
            'reconstruction' => [
                'algorithm' => 'lucy-reversible-delta',
                'version' => 1,
                'derivation_sha256' => str_repeat('f', 64),
                'value_encoding' => 'lucy-history-display-v1',
                'sources' => [
                    'Live' => [
                        'status' => 'chain-verified-anchored',
                        'revision_count' => 2,
                        'change_count' => 2,
                        'tracked_field_count' => 1,
                        'continuity_checks' => 0,
                    ],
                ],
            ],
            'current_raw' => [
                'Live' => [
                    'source' => 'Live',
                    'fields' => [
                        'id' => (string) $itemId,
                        'name' => $name,
                        'ac' => '12',
                    ],
                    'capture_sha256' => $rawHash,
                ],
            ],
            'revisions' => [
                [
                    'entry_id' => $itemId * 10 + 1,
                    'source' => 'Live',
                    'observed_at' => '2019-01-01T12:30:00',
                    'observed_precision' => 'minute',
                    'type' => 'initial',
                    'changes' => [[
                        'operation' => 'initial',
                        'field' => null,
                        'before' => null,
                        'after' => null,
                        'display' => 'Initial entry',
                    ]],
                    'detail_fidelity' => 'reconstructed',
                    'history_capture_sha256s' => [$historyHash],
                ],
                [
                    'entry_id' => $itemId * 10 + 2,
                    'source' => 'Live',
                    'observed_at' => '2020-02-02T13:45:10',
                    'observed_precision' => 'second',
                    'type' => 'changed',
                    'changes' => [[
                        'operation' => 'changed',
                        'field' => 'ac',
                        'before' => '10',
                        'after' => '12',
                        'display' => 'AC changed from 10 to 12',
                    ]],
                    'detail_fidelity' => 'captured',
                    'detail' => [
                        'name' => $name,
                        'icon' => 512,
                        'snapshot_lines' => ['AC: 12'],
                    ],
                    'capture_sha256' => str_repeat('b', 64),
                    'history_capture_sha256s' => [$historyHash],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function directItemHistoryPackageArtifact(int $itemId): array
    {
        $artifact = $this->itemHistoryPackageArtifact($itemId);
        $artifact['format_version'] = ItemHistoryArtifact::FORMAT_VERSION;
        unset(
            $artifact['parser_format_version'],
            $artifact['capture_strategy'],
            $artifact['coverage'],
            $artifact['evidence'],
            $artifact['reconstruction'],
            $artifact['current_raw'],
        );
        foreach ($artifact['revisions'] as $index => &$revision) {
            unset($revision['detail_fidelity'], $revision['history_capture_sha256s']);
            $revision['detail'] ??= [
                'name' => $artifact['latest_name'],
                'icon' => $artifact['latest_icon'],
                'snapshot_lines' => [$index === 0 ? 'AC: 10' : 'AC: 12'],
            ];
            $revision['capture_sha256'] ??= str_repeat($index === 0 ? 'a' : 'b', 64);
        }
        unset($revision);

        return $artifact;
    }

    /** @param array<string, mixed> $value */
    private function itemHistoryPackageJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    private function removeItemHistoryPackageTree(string $path): void
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
