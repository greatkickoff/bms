<?php

/**
 * BookmarkParser
 * Parses bookmark files from Firefox (JSON) and Chrome/Safari (Netscape HTML).
 * Returns a flat array of bookmarks in a unified format:
 * [
 *   'url'    => string,
 *   'title'  => string,
 *   'folder' => string,   // folder path, e.g. "Toolbar > News"
 *   'added'  => int,      // unix timestamp (0 if unknown)
 * ]
 */
class BookmarkParser
{
    /**
     * Parse a Firefox bookmark export (JSON, Mozilla Places format).
     */
    public static function parseFirefox(string $content): array
    {
        $data = json_decode($content, true);
        if ($data === null) {
            throw new \InvalidArgumentException('Ungültige Firefox-JSON-Datei.');
        }
        $bookmarks = [];
        self::walkFirefoxNode($data, '', $bookmarks);
        return $bookmarks;
    }

    private static function walkFirefoxNode(array $node, string $path, array &$out): void
    {
        $type = $node['type'] ?? '';

        if ($type === 'text/x-moz-place') {
            $uri = $node['uri'] ?? '';
            // Skip separators, javascript: URIs and empty entries
            if ($uri === '' || str_starts_with($uri, 'javascript:') || str_starts_with($uri, 'place:')) {
                return;
            }
            $addedRaw = $node['dateAdded'] ?? 0;
            $out[] = [
                'url'    => $uri,
                'title'  => $node['title'] ?? $uri,
                'folder' => $path,
                'added'  => $addedRaw > 0 ? (int)($addedRaw / 1_000_000) : 0,
            ];
        } elseif ($type === 'text/x-moz-place-container') {
            $title = $node['title'] ?? '';
            // Skip the synthetic root containers (empty title = placesRoot)
            if ($title === '') {
                $newPath = $path;
            } else {
                $newPath = $path !== '' ? $path . ' > ' . $title : $title;
            }
            foreach ($node['children'] ?? [] as $child) {
                self::walkFirefoxNode($child, $newPath, $out);
            }
        }
    }

    /**
     * Parse a Chrome or Safari bookmark export (Netscape HTML format).
     */
    public static function parseHtml(string $content): array
    {
        // DOMDocument is strict; suppress warnings from the non-standard Netscape format
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
        libxml_clear_errors();

        $bookmarks = [];

        // The root <DL> is the first one in the document
        $dlList = $dom->getElementsByTagName('dl');
        if ($dlList->length === 0) {
            // Fallback: try to parse with regex
            return self::parseHtmlRegex($content);
        }

        self::walkNetscapeNode($dlList->item(0), [], $bookmarks);
        return $bookmarks;
    }

    /**
     * Walk a <DL> element and collect bookmarks recursively.
     * In Netscape format, inside a <DL>:
     *   - <DT><H3>Folder</H3>  followed by sibling <DL>...</DL>
     *   - <DT><A HREF="...">Title</A>
     */
    private static function walkNetscapeNode(\DOMNode $dl, array $folderPath, array &$out): void
    {
        $pendingFolder = null;

        foreach ($dl->childNodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($node->nodeName);

            if ($tag === 'dt') {
                // Find the first element child (H3 or A)
                $child = self::firstElementChild($node);
                if ($child === null) {
                    continue;
                }
                $childTag = strtolower($child->nodeName);

                if ($childTag === 'a') {
                    $href = $child->getAttribute('href');
                    if ($href && !str_starts_with($href, 'javascript:')) {
                        $addDate = (int)($child->getAttribute('add_date') ?: 0);
                        $out[] = [
                            'url'    => $href,
                            'title'  => trim($child->textContent),
                            'folder' => implode(' > ', $folderPath),
                            'added'  => $addDate,
                        ];
                    }
                    $pendingFolder = null;
                } elseif ($childTag === 'h3') {
                    $pendingFolder = trim($child->textContent);
                }
            } elseif ($tag === 'dl' && $pendingFolder !== null) {
                // This DL belongs to the folder named in $pendingFolder
                $newPath = array_merge($folderPath, [$pendingFolder]);
                self::walkNetscapeNode($node, $newPath, $out);
                $pendingFolder = null;
            } elseif ($tag === 'dl' && $pendingFolder === null) {
                // Nested DL without a preceding folder header – treat as continuation
                self::walkNetscapeNode($node, $folderPath, $out);
            }
        }
    }

    /** Returns the first element child of a DOMNode, or null. */
    private static function firstElementChild(\DOMNode $node): ?\DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                return $child;
            }
        }
        return null;
    }

    /**
     * Regex-based fallback for malformed HTML.
     */
    private static function parseHtmlRegex(string $content): array
    {
        $bookmarks = [];
        // Match <A HREF="..." ...>Title</A> – capture href, add_date, title
        preg_match_all(
            '/<a\s[^>]*href="([^"]+)"[^>]*(?:add_date="(\d+)")?[^>]*>([^<]*)<\/a>/i',
            $content,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $m) {
            $href = $m[1];
            if (str_starts_with($href, 'javascript:')) {
                continue;
            }
            $bookmarks[] = [
                'url'    => $href,
                'title'  => html_entity_decode(trim($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'folder' => '',
                'added'  => (int)($m[2] ?? 0),
            ];
        }
        return $bookmarks;
    }
}
