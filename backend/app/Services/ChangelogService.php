<?php

namespace App\Services;

class ChangelogService
{
    /**
     * Parse CHANGELOG.md and return structured entries.
     *
     * @param int $page
     * @param int $perPage
     * @return array{data: array, meta: array}
     */
    public function getEntries(int $page = 1, int $perPage = 10): array
    {
        $changelogPath = base_path('../CHANGELOG.md');

        if (!file_exists($changelogPath)) {
            return [
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
            ];
        }

        $content = file_get_contents($changelogPath);
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        $entries = $this->parseChangelog($content);

        $total = count($entries);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;

        return [
            'data' => array_values(array_slice($entries, $offset, $perPage)),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * Parse Keep a Changelog format into structured array.
     *
     * @param string $content Raw markdown content
     * @return array<int, array{version: string, date: string|null, categories: array}>
     */
    private function parseChangelog(string $content): array
    {
        $entries = [];
        $currentEntry = null;
        $currentCategory = null;

        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Match version headers: ## [1.2.0] - 2026-02-10 or ## [Unreleased]
            if (preg_match('/^## \[([^\]]+)\](?:\s*-\s*(.+))?/', $trimmed, $matches)) {
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }

                $currentEntry = [
                    'version' => $matches[1],
                    'date' => isset($matches[2]) ? trim($matches[2]) : null,
                    'categories' => [],
                ];
                $currentCategory = null;
                continue;
            }

            // Match category headers: ### Added, ### Fixed, etc.
            if (preg_match('/^### (.+)/', $trimmed, $matches) && $currentEntry !== null) {
                $currentCategory = strtolower(trim($matches[1]));
                if (!isset($currentEntry['categories'][$currentCategory])) {
                    $currentEntry['categories'][$currentCategory] = [];
                }
                continue;
            }

            // Match list items: - Some change description
            if (preg_match('/^- (.+)/', $trimmed, $matches) && $currentEntry !== null && $currentCategory !== null) {
                $currentEntry['categories'][$currentCategory][] = trim($matches[1]);
                continue;
            }
        }

        // Don't forget the last entry
        if ($currentEntry !== null) {
            $entries[] = $currentEntry;
        }

        return $entries;
    }
}
