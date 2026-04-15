<?php

/**
 * BookmarkParser
 * Parses bookmark files from Firefox (JSON) and Chrome/Safari (Netscape HTML).
 * Returns a flat array of bookmarks in a unified format:
 * [
 *   'url'    => string,
 *   'title'  => string,
 *   'folder' => string,   // full folder path, e.g. "Toolbar > News > Tech"
 *   'added'  => int,      // unix timestamp (0 if unknown)
 * ]
 */
class BookmarkParser
{
    // ── Firefox JSON (Mozilla Places format) ─────────────────────────────────

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
            $title   = $node['title'] ?? '';
            $newPath = ($title === '') ? $path : ($path !== '' ? $path . ' > ' . $title : $title);
            foreach ($node['children'] ?? [] as $child) {
                self::walkFirefoxNode($child, $newPath, $out);
            }
        }
    }

    // ── Netscape HTML (Chrome / Safari / Firefox HTML export) ────────────────

    /**
     * Pure token-based parser for the Netscape Bookmark HTML format.
     *
     * Why not DOMDocument?
     * DOMDocument re-nests <DL> tags inside <DT> elements (because DT has no
     * explicit close tag in Netscape files), causing the entire folder tree to
     * collapse. A token-based pass over the raw text is far more reliable.
     */
    public static function parseHtml(string $content): array
    {
        // ── Pre-processing ───────────────────────────────────────────────────
        // Normalise line endings
        $content = str_replace("\r\n", "\n", str_replace("\r", "\n", $content));

        // Remove base64 ICON data – can be 100 KB per bookmark, causes catastrophic
        // backtracking and is useless for our purposes.
        $content = preg_replace('/\s+ICON="data:[^"]*"/i', '', $content);

        // Strip HTML comments
        $content = preg_replace('/<!--.*?-->/s', '', $content);

        // ── Tokenise ─────────────────────────────────────────────────────────
        // Split into alternating [text, tag, text, tag, …] pieces.
        // PREG_SPLIT_DELIM_CAPTURE keeps the tags in the output array.
        $tokens = preg_split('/(<[^>]+>)/s', $content, -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        // ── State machine ─────────────────────────────────────────────────────
        $bookmarks = [];

        // folderByDepth[depth] = full path string at that DL nesting level.
        // Depth 0 is the document root (before any DL is opened).
        $folderByDepth  = [0 => ''];
        $depth          = 0;
        $pendingFolder  = null;   // folder name waiting for the next <DL>

        $inA     = false;
        $inH3    = false;
        $aHref   = '';
        $aAdded  = 0;
        $aBuf    = '';   // accumulates text content of current <A>
        $h3Buf   = '';   // accumulates text content of current <H3>

        foreach ($tokens as $tok) {
            // ── Plain text ───────────────────────────────────────────────────
            if ($tok === '' || $tok[0] !== '<') {
                if ($inA)  $aBuf  .= $tok;
                if ($inH3) $h3Buf .= $tok;
                continue;
            }

            // ── Extract tag name (with optional leading slash) ───────────────
            // e.g. "<DL>" → "dl",  "</DL>" → "/dl",  "<A HREF=...>" → "a"
            $tagRaw = '';
            if (preg_match('/^<\s*(\/?\s*[\w]+)/i', $tok, $tm)) {
                $tagRaw = strtolower(trim($tm[1]));
            }

            switch ($tagRaw) {

                // ── <DL> : enter a new folder level ──────────────────────────
                case 'dl':
                    $depth++;
                    if ($pendingFolder !== null) {
                        $parent = $folderByDepth[$depth - 1] ?? '';
                        $folderByDepth[$depth] = $parent !== ''
                            ? $parent . ' > ' . $pendingFolder
                            : $pendingFolder;
                        $pendingFolder = null;
                    } else {
                        // No folder header before this DL: inherit parent path
                        $folderByDepth[$depth] = $folderByDepth[$depth - 1] ?? '';
                    }
                    break;

                // ── </DL> : leave folder level ────────────────────────────────
                case '/dl':
                    unset($folderByDepth[$depth]);
                    if ($depth > 0) $depth--;
                    break;

                // ── <H3> : start of a folder name ────────────────────────────
                case 'h3':
                    $inH3  = true;
                    $h3Buf = '';
                    break;

                // ── </H3> : folder name complete ─────────────────────────────
                case '/h3':
                    $inH3         = false;
                    $pendingFolder = html_entity_decode(
                        trim(strip_tags($h3Buf)), ENT_QUOTES | ENT_HTML5, 'UTF-8'
                    );
                    break;

                // ── <A> : start of a bookmark ────────────────────────────────
                case 'a':
                    $inA   = true;
                    $aBuf  = '';
                    $aHref = '';
                    $aAdded = 0;

                    // href – try double-quotes first, then single quotes
                    if (preg_match('/\bhref="([^"]*)"/i', $tok, $m)) {
                        $aHref = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    } elseif (preg_match("/\\bhref='([^']*)'/i", $tok, $m)) {
                        $aHref = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }

                    // add_date
                    if (preg_match('/\badd_date="(\d+)"/i', $tok, $m)) {
                        $aAdded = (int)$m[1];
                    }
                    break;

                // ── </A> : emit bookmark ──────────────────────────────────────
                case '/a':
                    $inA = false;
                    if ($aHref !== '' && !str_starts_with(strtolower($aHref), 'javascript:')) {
                        $folder = $folderByDepth[$depth] ?? ($folderByDepth ? end($folderByDepth) : '');
                        $title  = html_entity_decode(
                            trim(strip_tags($aBuf)), ENT_QUOTES | ENT_HTML5, 'UTF-8'
                        );
                        $bookmarks[] = [
                            'url'    => $aHref,
                            'title'  => $title !== '' ? $title : $aHref,
                            'folder' => $folder,
                            'added'  => $aAdded,
                        ];
                    }
                    break;
            }
        }

        return $bookmarks;
    }
}
