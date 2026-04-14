<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/src/BookmarkParser.php';
require_once __DIR__ . '/src/BookmarkSync.php';
require_once __DIR__ . '/src/BookmarkExporter.php';

// ── Constants ────────────────────────────────────────────────────────────────
const BROWSERS = [
    'firefox' => ['label' => 'Firefox', 'icon' => '🦊', 'hint' => 'JSON- oder HTML-Datei'],
    'chrome'  => ['label' => 'Chrome',  'icon' => '🌐', 'hint' => 'HTML-Datei (.html)'],
    'safari'  => ['label' => 'Safari',  'icon' => '🧭', 'hint' => 'HTML-Datei (.html)'],
];

const UPLOAD_DIR = __DIR__ . '/uploads';
const MAX_AGE    = 86400; // 24 h – cleanup old uploads

// ── Helpers (early, needed during POST) ─────────────────────────────────────
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Auto-detect whether content is Firefox JSON or Netscape HTML and parse it.
 */
function parseAuto(string $content, string $browser): array {
    $trimmed = ltrim($content);
    if ($trimmed !== '' && $trimmed[0] === '{') {
        // JSON → Firefox format
        return BookmarkParser::parseFirefox($content);
    }
    // HTML → Netscape format (Chrome, Safari, Firefox HTML export)
    return BookmarkParser::parseHtml($content);
}

/**
 * Save a file to the session upload directory and return its path.
 */
function saveUpload(string $sessionDir, string $browser, string $content): string {
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0755, true);
    }
    $path = $sessionDir . '/' . $browser . '.dat';
    file_put_contents($path, $content);
    return $path;
}

/**
 * Remove upload directories older than MAX_AGE seconds.
 */
function cleanupOldUploads(): void {
    $entries = glob(UPLOAD_DIR . '/sess_*', GLOB_ONLYDIR);
    if (!$entries) return;
    $cutoff = time() - MAX_AGE;
    foreach ($entries as $dir) {
        if (filemtime($dir) < $cutoff) {
            array_map('unlink', glob($dir . '/*'));
            @rmdir($dir);
        }
    }
}

// ── Handle POST (upload) ─────────────────────────────────────────────────────
$errors  = [];
$result  = null;

// Periodic cleanup of old upload directories
cleanupOldUploads();

// Session-based upload directory
$sessionDir = UPLOAD_DIR . '/sess_' . session_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['reset'])) {
        // Remove uploaded files for this session
        if (is_dir($sessionDir)) {
            array_map('unlink', glob($sessionDir . '/*'));
            rmdir($sessionDir);
        }
        unset($_SESSION['sync_result'], $_SESSION['uploaded_files']);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $sources = [];

    foreach (BROWSERS as $key => $meta) {
        $file = $_FILES[$key] ?? null;

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            // Re-use previously stored file for this browser if available
            $stored = $_SESSION['uploaded_files'][$key] ?? null;
            if ($stored && file_exists($stored)) {
                $content = file_get_contents($stored);
                if ($content !== false) {
                    try {
                        $sources[$key] = parseAuto($content, $key);
                    } catch (\Throwable) {}
                }
            }
            continue;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Upload-Fehler bei {$meta['label']}: Fehlercode {$file['error']}.";
            continue;
        }

        if ($file['size'] > 20 * 1024 * 1024) {
            $errors[] = "{$meta['label']}: Datei zu groß (max. 20 MB).";
            continue;
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            $errors[] = "{$meta['label']}: Datei konnte nicht gelesen werden.";
            continue;
        }

        try {
            $bookmarks = parseAuto($content, $key);

            if (empty($bookmarks)) {
                $errors[] = "{$meta['label']}: Keine Lesezeichen gefunden. Prüfe das Dateiformat.";
                continue;
            }

            // Persist file on server
            $path = saveUpload($sessionDir, $key, $content);
            $_SESSION['uploaded_files'][$key] = $path;

            $sources[$key] = $bookmarks;
        } catch (\Throwable $e) {
            $errors[] = "{$meta['label']}: Fehler beim Verarbeiten – " . h($e->getMessage());
        }
    }

    if (count($sources) < 1) {
        $errors[] = 'Bitte lade mindestens eine Lesezeichen-Datei hoch.';
    } elseif (empty($errors)) {
        $sync   = new BookmarkSync($sources);
        $result = $sync->sync();
        $_SESSION['sync_result'] = $result;
    }
}

// Load previous result from session if not freshly computed
if ($result === null && isset($_SESSION['sync_result'])) {
    $result = $_SESSION['sync_result'];
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function browserBadges(array $entry, array $browsers): string {
    $out = '';
    foreach ($browsers as $b) {
        $meta = BROWSERS[$b] ?? ['label' => $b];
        $initial = strtoupper($b[0]);
        $on   = isset($entry['sources'][$b]) ? 'badge-on' : 'badge-off';
        $out .= "<span class=\"badge badge-{$b} {$on}\" title=\"{$meta['label']}\">{$initial}</span>";
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bookmark Sync</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header>
  <div class="container">
    <div>
      <div class="logo">Bookmark Sync</div>
      <div class="logo-sub">Firefox · Chrome · Safari – synchronisiert</div>
    </div>
  </div>
</header>

<main>
  <div class="container">

    <!-- ── Upload Card ─────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-title">
        📂 Lesezeichen hochladen
      </div>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
          <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="alert alert-info">
        <strong>Export-Anleitung:</strong><br>
        <strong>Firefox:</strong> Lesezeichen-Menü → Alle Lesezeichen anzeigen → Import und Sicherung → Als HTML exportieren <em>oder</em> In JSON exportieren<br>
        <strong>Chrome:</strong> Lesezeichen-Manager (⋮) → Lesezeichen exportieren (.html)<br>
        <strong>Safari:</strong> Ablage → Lesezeichen exportieren (.html)
      </div>

      <form method="post" enctype="multipart/form-data" id="upload-form">

        <div class="upload-grid">
          <?php foreach (BROWSERS as $key => $meta): ?>
          <div class="upload-slot slot-<?= $key ?>" id="slot-<?= $key ?>">
            <input type="file"
                   name="<?= $key ?>"
                   id="file-<?= $key ?>"
                   accept=".json,.html,.htm"
                   data-slot="<?= $key ?>">
            <span class="browser-icon"><?= $meta['icon'] ?></span>
            <div class="browser-label"><?= $meta['label'] ?></div>
            <div class="upload-hint">Klicken oder Datei hierher ziehen<br><small><?= $meta['hint'] ?></small></div>
            <div class="file-chosen" id="chosen-<?= $key ?>"></div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="action-row">
          <button type="submit" class="btn btn-primary btn-lg" id="sync-btn">
            🔄 Synchronisieren
          </button>
          <?php if ($result !== null): ?>
          <button type="submit" name="reset" value="1" class="btn btn-secondary">
            ✕ Zurücksetzen
          </button>
          <?php endif; ?>
        </div>

      </form>
    </div>

    <?php if ($result !== null): ?>
    <?php
      $browsers = $result['browsers'];
      $merged   = $result['merged'];
      $stats    = $result['stats'];
      $subsets  = $result['subsets'];
    ?>

    <!-- ── Stats ─────────────────────────────────────────────────────── -->
    <section class="mt-3">
      <div class="section-title">📊 Übersicht</div>
      <div class="stats-grid">
        <div class="stat-box">
          <div class="stat-num"><?= $stats['total'] ?></div>
          <div class="stat-label">Gesamt (eindeutig)</div>
        </div>
        <?php foreach ($browsers as $b): ?>
        <div class="stat-box">
          <div class="stat-num" style="color: var(--<?= $b ?>)"><?= $stats[$b] ?></div>
          <div class="stat-label"><?= BROWSERS[$b]['icon'] ?> <?= BROWSERS[$b]['label'] ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (count($browsers) > 1): ?>
        <div class="stat-box">
          <div class="stat-num" style="color: var(--ok)"><?= $stats['in_all'] ?></div>
          <div class="stat-label">In allen Browsern</div>
        </div>
        <div class="stat-box">
          <div class="stat-num" style="color: var(--warn)"><?= $stats['unique_to_one'] ?></div>
          <div class="stat-label">Nur in einem Browser</div>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- ── Downloads ─────────────────────────────────────────────────── -->
    <section class="mt-3">
      <div class="section-title">⬇️ Synchronisierte Lesezeichen herunterladen</div>
      <div class="download-grid">
        <?php foreach (BROWSERS as $key => $meta): ?>
        <div class="download-card">
          <span class="browser-icon"><?= $meta['icon'] ?></span>
          <div class="d-title"><?= $meta['label'] ?></div>
          <div class="d-sub">
            <?php if ($key === 'firefox'): ?>JSON-Format<?php else: ?>HTML (Netscape)<?php endif; ?>
            · <?= count($merged) ?> Einträge
          </div>
          <a href="export.php?browser=<?= $key ?>" class="btn btn-<?= $key ?>">
            ⬇️ Herunterladen
          </a>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- ── Diff Table ─────────────────────────────────────────────────── -->
    <section class="mt-3">
      <div class="section-title">🔍 Unterschiede &amp; alle Lesezeichen</div>

      <div class="diff-controls">
        <span class="filter-chip active" data-filter="all">Alle (<?= count($merged) ?>)</span>

        <?php if (count($browsers) > 1): ?>
          <span class="filter-chip" data-filter="in_all"
                title="In allen hochgeladenen Browsern vorhanden">
            In allen (<?= $stats['in_all'] ?>)
          </span>
          <span class="filter-chip" data-filter="unique"
                title="Nur in einem Browser vorhanden">
            Nur in einem (<?= $stats['unique_to_one'] ?>)
          </span>
        <?php endif; ?>

        <?php foreach ($browsers as $b): ?>
          <?php $onlyKey = $b; // rows where ONLY this browser has it ?>
          <span class="filter-chip" data-filter="only_<?= $b ?>"
                style="--chip-color: var(--<?= $b ?>)"
                title="Nur in <?= BROWSERS[$b]['label'] ?>">
            Nur <?= BROWSERS[$b]['icon'] ?> <?= BROWSERS[$b]['label'] ?>
            (<?= count(array_filter($merged, fn($e) => array_keys($e['sources']) === [$b])) ?>)
          </span>
        <?php endforeach; ?>

        <div class="search-box">
          <span class="search-icon">🔍</span>
          <input type="text" id="search-input" placeholder="Suchen…" autocomplete="off">
        </div>
      </div>

      <div class="table-wrap">
        <table id="bookmark-table">
          <thead>
            <tr>
              <th class="td-title">Titel</th>
              <th class="td-url">URL</th>
              <th class="td-folder">Ordner</th>
              <th class="td-browsers">Browser</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($merged as $entry):
              $sourcesStr = implode(',', array_keys($entry['sources']));
              $inAll  = count($entry['sources']) === count($browsers) && count($browsers) > 1;
              $unique = count($entry['sources']) === 1;
              $onlyBrowser = $unique ? array_key_first($entry['sources']) : '';
            ?>
            <tr class="bm-row"
                data-sources="<?= h($sourcesStr) ?>"
                data-in-all="<?= $inAll ? '1' : '0' ?>"
                data-unique="<?= $unique ? '1' : '0' ?>"
                data-only="<?= h($onlyBrowser) ?>">
              <td class="td-title">
                <a href="<?= h($entry['url']) ?>"
                   class="cell-title"
                   target="_blank"
                   rel="noopener noreferrer"
                   title="<?= h($entry['title']) ?>">
                  <?= h($entry['title'] ?: '(kein Titel)') ?>
                </a>
              </td>
              <td class="td-url">
                <span class="cell-url" title="<?= h($entry['url']) ?>"><?= h($entry['url']) ?></span>
              </td>
              <td class="td-folder">
                <span class="cell-folder" title="<?= h($entry['folder']) ?>"><?= h($entry['folder'] ?: '—') ?></span>
              </td>
              <td class="td-browsers">
                <?= browserBadges($entry, $browsers) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="table-footer">
          <span id="row-count"><?= count($merged) ?> Einträge</span>
          <span>Klicke auf einen Titel zum Öffnen</span>
        </div>
      </div>
    </section>

    <?php endif; // $result !== null ?>

  </div><!-- /.container -->
</main>

<script>
// ── File input labels ──────────────────────────────────────────────────────
document.querySelectorAll('input[type="file"][data-slot]').forEach(input => {
  const slot   = document.getElementById('slot-' + input.dataset.slot);
  const chosen = document.getElementById('chosen-' + input.dataset.slot);

  input.addEventListener('change', () => {
    if (input.files.length > 0) {
      slot.classList.add('has-file');
      chosen.textContent = input.files[0].name;
    } else {
      slot.classList.remove('has-file');
      chosen.textContent = '';
    }
  });

  // Drag & drop visual feedback
  slot.addEventListener('dragover',  e => { e.preventDefault(); slot.classList.add('drag-over'); });
  slot.addEventListener('dragleave', () => slot.classList.remove('drag-over'));
  slot.addEventListener('drop',      () => slot.classList.remove('drag-over'));
});

// ── Spinner on submit ─────────────────────────────────────────────────────
document.getElementById('upload-form')?.addEventListener('submit', function(e) {
  if (e.submitter?.name === 'reset') return;
  const btn = document.getElementById('sync-btn');
  btn.innerHTML = '<span class="spinner"></span> Verarbeite…';
  btn.disabled = true;
});

// ── Filter chips ──────────────────────────────────────────────────────────
const chips = document.querySelectorAll('.filter-chip');
const rows  = document.querySelectorAll('.bm-row');
const count = document.getElementById('row-count');
const searchInput = document.getElementById('search-input');

let activeFilter = 'all';
let searchQuery  = '';

function applyFilters() {
  let visible = 0;
  rows.forEach(row => {
    const matchesFilter = checkFilter(row, activeFilter);
    const matchesSearch = checkSearch(row, searchQuery);
    const show = matchesFilter && matchesSearch;
    row.classList.toggle('hidden', !show);
    if (show) visible++;
  });
  if (count) count.textContent = visible + ' Einträge';
}

function checkFilter(row, filter) {
  if (filter === 'all')    return true;
  if (filter === 'in_all') return row.dataset.inAll === '1';
  if (filter === 'unique') return row.dataset.unique === '1';
  if (filter.startsWith('only_')) {
    const b = filter.replace('only_', '');
    return row.dataset.only === b;
  }
  return true;
}

function checkSearch(row, query) {
  if (!query) return true;
  const text = row.textContent.toLowerCase();
  return text.includes(query.toLowerCase());
}

chips.forEach(chip => {
  chip.addEventListener('click', () => {
    chips.forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    activeFilter = chip.dataset.filter;
    applyFilters();
  });
});

searchInput?.addEventListener('input', e => {
  searchQuery = e.target.value.trim();
  applyFilters();
});
</script>

</body>
</html>
