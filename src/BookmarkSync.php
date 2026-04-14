<?php

/**
 * BookmarkSync
 *
 * Merges bookmark lists from multiple browsers and calculates the differences.
 *
 * Input:  array<browser => bookmark[]>   (browser = 'firefox'|'chrome'|'safari')
 * Output: SyncResult with:
 *   - merged   : unified list (all unique bookmarks)
 *   - bySource : bookmarks keyed by presence bitmask / source combination
 *   - stats    : summary counts
 */
class BookmarkSync
{
    /** @var array<string, array> */
    private array $sources;

    /** @param array<string, array> $sources  e.g. ['firefox' => [...], 'chrome' => [...]] */
    public function __construct(array $sources)
    {
        $this->sources = $sources;
    }

    public function sync(): array
    {
        // ── 1. Build a unified map keyed by normalised URL ──────────────────
        $map = [];   // normalised_url => unified entry

        foreach ($this->sources as $browser => $bookmarks) {
            foreach ($bookmarks as $bm) {
                $key = self::normaliseUrl($bm['url']);
                if (!isset($map[$key])) {
                    $map[$key] = [
                        'url'     => $bm['url'],
                        'title'   => $bm['title'],
                        'folder'  => $bm['folder'],
                        'added'   => $bm['added'],
                        'sources' => [],
                    ];
                }
                // Prefer the entry with the most recent add date, or non-empty title
                if (
                    ($bm['added'] > ($map[$key]['added'] ?? 0)) ||
                    ($map[$key]['title'] === '' && $bm['title'] !== '')
                ) {
                    $map[$key]['title']  = $bm['title'];
                    $map[$key]['folder'] = $bm['folder'];
                    $map[$key]['added']  = $bm['added'];
                }
                $map[$key]['sources'][$browser] = true;
            }
        }

        // ── 2. Sort merged list by folder then title ─────────────────────────
        $merged = array_values($map);
        usort($merged, static function (array $a, array $b): int {
            $folderCmp = strcasecmp($a['folder'], $b['folder']);
            return $folderCmp !== 0 ? $folderCmp : strcasecmp($a['title'], $b['title']);
        });

        // ── 3. Build per-combination subsets ────────────────────────────────
        $browsers = array_keys($this->sources);
        $subsets  = [];

        foreach ($merged as $entry) {
            $inSources = array_keys($entry['sources']);
            sort($inSources);
            $key = implode('+', $inSources);
            $subsets[$key][] = $entry;
        }

        // ── 4. Statistics ────────────────────────────────────────────────────
        $stats = ['total' => count($merged)];
        foreach ($browsers as $b) {
            $stats[$b] = count(array_filter($merged, fn($e) => isset($e['sources'][$b])));
        }
        $stats['in_all'] = count(array_filter(
            $merged,
            fn($e) => count($e['sources']) === count($browsers) && count($browsers) > 1
        ));
        $stats['unique_to_one'] = count(array_filter(
            $merged,
            fn($e) => count($e['sources']) === 1
        ));

        return [
            'browsers' => $browsers,
            'merged'   => $merged,
            'subsets'  => $subsets,
            'stats'    => $stats,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Normalise a URL for deduplication:
     * - lowercase scheme + host
     * - strip trailing slash
     * - strip common tracking parameters
     * - strip fragment
     */
    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return strtolower($url);
        }

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host   = strtolower($parsed['host']);
        $path   = rtrim($parsed['path'] ?? '/', '/');
        if ($path === '') {
            $path = '';
        }

        // Rebuild query without known tracking params
        $query = '';
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            // Remove common tracking/session parameters
            $tracking = [
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'fbclid', 'gclid', 'ref', 'source', '_ga', 'mc_cid', 'mc_eid',
            ];
            foreach ($tracking as $t) {
                unset($params[$t]);
            }
            if ($params) {
                ksort($params);
                $query = '?' . http_build_query($params);
            }
        }

        return $scheme . '://' . $host . $path . $query;
    }
}
