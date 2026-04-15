<?php
/**
 * Portal – Einfaches PHP-Portal (DokuWiki-Ersatz)
 * ─────────────────────────────────────────────────
 * Konfiguration: $config-Array unten anpassen.
 * Kachelgrösse: S / M / L über den Button oben links umschalten.
 */

// ═══════════════════════════════════════════════════════════════════════════════
//  KONFIGURATION  ← hier alles anpassen
// ═══════════════════════════════════════════════════════════════════════════════

$config = [

    'title'   => 'Portal',
    'columns' => 3,   // Anzahl Spalten im Kachel-Raster (1–12)

    // ── Schnell-Links in der oberen Navigationsleiste ─────────────────────────
    'nav' => [
        ['label' => 'UBS e-Banking',  'url' => 'https://www.ubs.com/'],
        ['label' => 'RSS News',       'url' => '#'],
        ['label' => 'Kerio Webmail',  'url' => '#'],
        ['label' => 'Dokuwiki',       'url' => '#'],
        ['label' => 'Nagios',         'url' => '#'],
        ['label' => 'Mobile Blog',    'url' => '#'],
        ['label' => 'LinkSources',    'url' => '#'],
    ],

    // ── Kacheln ───────────────────────────────────────────────────────────────
    // Jede Kachel hat einen 'title' und ein Array von 'links'.
    // Die Kacheln füllen das Raster von links nach rechts, Zeile für Zeile.
    'tiles' => [

        [
            'title' => 'Dokuwiki Links',
            'links' => [
                ['label' => 'Dashboard', 'url' => '#'],
            ],
        ],

        [
            'title' => 'Media-Photo',
            'links' => [
                ['label' => 'Photostation6 (192.168.0.96)', 'url' => 'http://192.168.0.96/'],
                ['label' => 'Gallery',                      'url' => '#'],
            ],
        ],

        [
            'title' => 'Links',
            'links' => [
                ['label' => 'OpenGroupware', 'url' => '#'],
                ['label' => 'Scalix',        'url' => '#'],
                ['label' => 'Webmail',       'url' => '#'],
                ['label' => 'Postfinance',   'url' => 'https://www.postfinance.ch/'],
                ['label' => 'ZKB',           'url' => 'https://www.zkb.ch/'],
                ['label' => 'E-Banking',     'url' => '#'],
                ['label' => 'Ferien',        'url' => '#'],
            ],
        ],

        [
            'title' => 'Serverlinks (Administration)',
            'links' => [
                ['label' => 'Software',                  'url' => '#'],
                ['label' => 'Wordpress',                 'url' => '#'],
                ['label' => 'wpmobile',                  'url' => '#'],
                ['label' => 'VMware vSphere Web Client', 'url' => '#'],
                ['label' => 'srv009 (server-status)',    'url' => '#'],
                ['label' => 'srv009 (server-info)',      'url' => '#'],
            ],
        ],

        [
            'title' => 'ScalixInstall',
            'links' => [
                ['label' => 'SpamAssassin', 'url' => '#'],
            ],
        ],

    ],

    // ── Footer-Links ──────────────────────────────────────────────────────────
    'footer' => [
        ['label' => 'Portal',            'url' => '#'],
        ['label' => 'Uebersicht Server', 'url' => '#'],
        ['label' => 'QuickNotes',        'url' => '#'],
        ['label' => 'mike',              'url' => '#'],
        ['label' => 'notes',             'url' => '#'],
    ],

];

// ═══════════════════════════════════════════════════════════════════════════════
//  HELPER
// ═══════════════════════════════════════════════════════════════════════════════

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$cols = max(1, min(12, (int)$config['columns']));

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($config['title']) ?></title>
  <style>
    /* ── Reset ───────────────────────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    /* ── Design-Tokens ───────────────────────────────────────────────────────── */
    :root {
      --bg:          #f4f4f4;
      --nav-bg:      #3a3a3a;
      --nav-fg:      #cccccc;
      --nav-hover:   #ffffff;
      --nav-sep:     #555;
      --tile-bg:     #ffffff;
      --tile-border: #d8d8d8;
      --tile-title:  #222;
      --link-fg:     #555;
      --link-hover:  #1a4e8a;
      --footer-fg:   #2a6099;
      --radius:      3px;
    }

    /* ── Grössen-Varianten (S / M / L) ──────────────────────────────────────── */
    /*    Werden per JS auf <body> gesetzt und in localStorage gespeichert.      */
    body.size-s {
      --fs-nav:   11px;
      --pad-nav:  5px 12px;
      --fs-title: 12px;
      --fs-link:  11px;
      --pad-tile: 8px 10px;
      --gap:      8px;
    }
    body.size-m {
      --fs-nav:   13px;
      --pad-nav:  7px 16px;
      --fs-title: 14px;
      --fs-link:  13px;
      --pad-tile: 12px 14px;
      --gap:      12px;
    }
    body.size-l {
      --fs-nav:   15px;
      --pad-nav:  10px 20px;
      --fs-title: 17px;
      --fs-link:  15px;
      --pad-tile: 18px 20px;
      --gap:      18px;
    }

    body {
      font-family: Arial, Helvetica, sans-serif;
      background: var(--bg);
      color: #333;
    }

    /* ── Navigationsleiste ───────────────────────────────────────────────────── */
    .topnav {
      background: var(--nav-bg);
      display:    flex;
      align-items: center;
      flex-wrap:  wrap;
    }

    /* Grössen-Toggle-Knopf ganz links */
    .size-toggle {
      display:     flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap:         1px;
      background:  #282828;
      border:      none;
      border-right: 2px solid #222;
      color:       #aaa;
      cursor:      pointer;
      padding:     var(--pad-nav, 7px 16px);
      font-family: inherit;
      transition:  background .15s, color .15s;
      min-width:   3em;
      white-space: nowrap;
    }
    .size-toggle:hover         { background: #111; color: #fff; }
    .size-toggle .st-letter    { font-size: var(--fs-nav, 13px); font-weight: bold; line-height: 1; }
    .size-toggle .st-hint      { font-size: 9px; color: #777; letter-spacing: .03em; line-height: 1; }

    /* Nav-Links */
    .topnav a {
      color:           var(--nav-fg);
      text-decoration: none;
      font-size:       var(--fs-nav, 13px);
      padding:         var(--pad-nav, 7px 16px);
      border-right:    1px solid var(--nav-sep);
      display:         block;
      white-space:     nowrap;
      transition:      color .15s, background .15s;
    }
    .topnav a:hover { color: var(--nav-hover); background: #4a4a4a; }

    /* ── Hauptbereich ────────────────────────────────────────────────────────── */
    .page-wrap {
      padding: 14px 16px 20px;
    }

    /* ── Kachel-Raster ───────────────────────────────────────────────────────── */
    .tile-grid {
      display:               grid;
      grid-template-columns: repeat(var(--cols), minmax(0, 1fr));
      gap:                   var(--gap, 12px);
      align-items:           start;   /* Kacheln haben individuelle Höhe */
    }

    /* ── Einzelne Kachel ─────────────────────────────────────────────────────── */
    .tile {
      background:    var(--tile-bg);
      border:        1px solid var(--tile-border);
      border-radius: var(--radius);
      padding:       var(--pad-tile, 12px 14px);
    }

    .tile-title {
      font-size:     var(--fs-title, 14px);
      font-weight:   bold;
      color:         var(--tile-title);
      padding-bottom: 5px;
      margin-bottom:  5px;
      border-bottom:  1px solid #ebebeb;
      line-height:    1.3;
    }

    .tile-links {
      list-style: none;
    }

    .tile-links li {
      margin: 3px 0;
    }

    .tile-links a {
      color:           var(--link-fg);
      text-decoration: none;
      font-size:       var(--fs-link, 13px);
      transition:      color .12s;
    }
    .tile-links a:hover { color: var(--link-hover); text-decoration: underline; }

    /* ── Footer ──────────────────────────────────────────────────────────────── */
    .page-footer {
      margin-top:  20px;
      padding-top: 10px;
      border-top:  1px dashed #ccc;
      font-size:   12px;
      line-height: 1.6;
    }

    .page-footer a           { color: var(--footer-fg); text-decoration: none; }
    .page-footer a:hover     { text-decoration: underline; }
    .page-footer .sep        { color: #aaa; margin: 0 3px; }
  </style>
</head>
<body class="size-m">

<!-- ── Navigationsleiste ─────────────────────────────────────────────────────── -->
<nav class="topnav">

  <button class="size-toggle" id="size-btn" onclick="cycleSize()"
          title="Kachelgrösse umschalten: S → M → L">
    <span class="st-letter" id="size-label">M</span>
    <span class="st-hint">Grösse</span>
  </button>

  <?php foreach ($config['nav'] as $link): ?>
  <a href="<?= h($link['url']) ?>"><?= h($link['label']) ?></a>
  <?php endforeach; ?>

</nav>

<!-- ── Kacheln ───────────────────────────────────────────────────────────────── -->
<div class="page-wrap">

  <div class="tile-grid" style="--cols: <?= $cols ?>">
    <?php foreach ($config['tiles'] as $tile): ?>
    <div class="tile">
      <div class="tile-title"><?= h($tile['title']) ?></div>
      <ul class="tile-links">
        <?php foreach ($tile['links'] as $link): ?>
        <li>
          <a href="<?= h($link['url']) ?>"
             target="_blank" rel="noopener noreferrer">
            <?= h($link['label']) ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Footer ──────────────────────────────────────────────────────────── -->
  <?php if (!empty($config['footer'])): ?>
  <div class="page-footer">
    <?php foreach ($config['footer'] as $i => $link): ?>
    <?php if ($i > 0): ?><span class="sep">,</span><?php endif; ?>
    <a href="<?= h($link['url']) ?>"><?= h($link['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /.page-wrap -->

<!-- ── Grössen-Toggle (S → M → L, gespeichert in localStorage) ───────────────── -->
<script>
(function () {
  const SIZES = ['s', 'm', 'l'];
  const KEY   = 'portal-tile-size';
  let   cur   = localStorage.getItem(KEY) || 'm';

  function apply(size) {
    document.body.className          = 'size-' + size;
    document.getElementById('size-label').textContent = size.toUpperCase();
    localStorage.setItem(KEY, size);
    cur = size;
  }

  window.cycleSize = function () {
    apply(SIZES[(SIZES.indexOf(cur) + 1) % SIZES.length]);
  };

  // Sofort beim Laden die gespeicherte Grösse anwenden (verhindert Flackern)
  apply(cur);
}());
</script>

</body>
</html>
