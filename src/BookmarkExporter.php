<?php

/**
 * BookmarkExporter
 *
 * Exports a merged bookmark list to browser-native formats.
 *
 * Supported targets:
 *   'firefox' → Mozilla Places JSON
 *   'chrome'  → Netscape HTML (Chrome format)
 *   'safari'  → Netscape HTML (Safari format)
 */
class BookmarkExporter
{
    /**
     * @param  array  $merged   The merged bookmark list produced by BookmarkSync
     * @param  string $target   'firefox' | 'chrome' | 'safari'
     * @return array  ['content' => string, 'filename' => string, 'mime' => string]
     */
    public static function export(array $merged, string $target): array
    {
        return match ($target) {
            'firefox' => self::exportFirefox($merged),
            'chrome'  => self::exportChrome($merged),
            'safari'  => self::exportSafari($merged),
            default   => throw new \InvalidArgumentException("Unbekanntes Ziel: $target"),
        };
    }

    // ── Firefox JSON ─────────────────────────────────────────────────────────

    private static function exportFirefox(array $merged): array
    {
        $now = (int)(microtime(true) * 1_000_000); // µs

        // Build a nested folder structure from the flat list
        $folders = [];   // path => [children]
        $root    = [];

        foreach ($merged as $bm) {
            $entry = [
                'guid'         => self::makeGuid(),
                'title'        => $bm['title'],
                'id'           => 0,
                'dateAdded'    => $bm['added'] > 0 ? $bm['added'] * 1_000_000 : $now,
                'lastModified' => $now,
                'type'         => 'text/x-moz-place',
                'uri'          => $bm['url'],
            ];

            $folder = $bm['folder'];
            if ($folder === '') {
                $root[] = $entry;
            } else {
                $folders[$folder][] = $entry;
            }
        }

        // Convert folder map to nested containers
        $menuChildren = $root;
        foreach ($folders as $path => $children) {
            $segments = explode(' > ', $path);
            $folderNode = [
                'guid'         => self::makeGuid(),
                'title'        => end($segments),
                'id'           => 0,
                'dateAdded'    => $now,
                'lastModified' => $now,
                'type'         => 'text/x-moz-place-container',
                'children'     => $children,
            ];
            $menuChildren[] = $folderNode;
        }

        $json = [
            'guid'         => 'root________',
            'title'        => '',
            'id'           => 1,
            'dateAdded'    => $now,
            'lastModified' => $now,
            'type'         => 'text/x-moz-place-container',
            'root'         => 'placesRoot',
            'children'     => [
                [
                    'guid'         => 'menu________',
                    'title'        => 'Lesezeichen-Menü',
                    'id'           => 2,
                    'dateAdded'    => $now,
                    'lastModified' => $now,
                    'type'         => 'text/x-moz-place-container',
                    'root'         => 'bookmarksMenuFolder',
                    'children'     => $menuChildren,
                ],
                [
                    'guid'         => 'toolbar_____',
                    'title'        => 'Lesezeichen-Symbolleiste',
                    'id'           => 3,
                    'dateAdded'    => $now,
                    'lastModified' => $now,
                    'type'         => 'text/x-moz-place-container',
                    'root'         => 'toolbarFolder',
                    'children'     => [],
                ],
                [
                    'guid'         => 'unfiled_____',
                    'title'        => 'Andere Lesezeichen',
                    'id'           => 4,
                    'dateAdded'    => $now,
                    'lastModified' => $now,
                    'type'         => 'text/x-moz-place-container',
                    'root'         => 'unfiledBookmarksFolder',
                    'children'     => [],
                ],
            ],
        ];

        // Assign sequential IDs
        self::assignIds($json, 1);

        return [
            'content'  => json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'filename' => 'bookmarks_synchronized_firefox.json',
            'mime'     => 'application/json',
        ];
    }

    private static int $idCounter = 5;

    private static function assignIds(array &$node, int $id): int
    {
        $node['id'] = $id++;
        foreach ($node['children'] ?? [] as &$child) {
            $id = self::assignIds($child, $id);
        }
        return $id;
    }

    // ── Chrome HTML ──────────────────────────────────────────────────────────

    private static function exportChrome(array $merged): array
    {
        $html = self::buildNetscapeHtml($merged, 'Bookmarks');
        return [
            'content'  => $html,
            'filename' => 'bookmarks_synchronized_chrome.html',
            'mime'     => 'text/html',
        ];
    }

    // ── Safari HTML ──────────────────────────────────────────────────────────

    private static function exportSafari(array $merged): array
    {
        $html = self::buildNetscapeHtml($merged, 'Bookmarks');
        return [
            'content'  => $html,
            'filename' => 'bookmarks_synchronized_safari.html',
            'mime'     => 'text/html',
        ];
    }

    // ── Shared Netscape HTML builder ─────────────────────────────────────────

    private static function buildNetscapeHtml(array $merged, string $title): string
    {
        $now  = time();
        $enc  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Group bookmarks by top-level folder (first segment)
        $groups  = [];
        $noGroup = [];

        foreach ($merged as $bm) {
            if ($bm['folder'] === '') {
                $noGroup[] = $bm;
            } else {
                $top = explode(' > ', $bm['folder'])[0];
                $groups[$top][] = $bm;
            }
        }

        $lines   = [];
        $lines[] = '<!DOCTYPE NETSCAPE-Bookmark-file-1>';
        $lines[] = '<!-- This is an automatically generated file by BookmarkSync -->';
        $lines[] = '<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">';
        $lines[] = "<TITLE>$title</TITLE>";
        $lines[] = "<H1>$title</H1>";
        $lines[] = '<DL><p>';

        // Bookmarks without a folder
        foreach ($noGroup as $bm) {
            $added = $bm['added'] > 0 ? $bm['added'] : $now;
            $lines[] = sprintf(
                '    <DT><A HREF="%s" ADD_DATE="%d">%s</A>',
                $enc($bm['url']),
                $added,
                $enc($bm['title'])
            );
        }

        // Folders
        foreach ($groups as $folderName => $bookmarks) {
            $lines[] = sprintf('    <DT><H3 ADD_DATE="%d">%s</H3>', $now, $enc($folderName));
            $lines[] = '    <DL><p>';
            foreach ($bookmarks as $bm) {
                $added = $bm['added'] > 0 ? $bm['added'] : $now;
                $lines[] = sprintf(
                    '        <DT><A HREF="%s" ADD_DATE="%d">%s</A>',
                    $enc($bm['url']),
                    $added,
                    $enc($bm['title'])
                );
            }
            $lines[] = '    </DL><p>';
        }

        $lines[] = '</DL><p>';
        return implode("\n", $lines) . "\n";
    }

    // ── Utility ──────────────────────────────────────────────────────────────

    private static function makeGuid(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
        $guid  = '';
        for ($i = 0; $i < 12; $i++) {
            $guid .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $guid;
    }
}
