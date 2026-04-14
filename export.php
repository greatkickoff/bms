<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/src/BookmarkExporter.php';

// ── Validate request ─────────────────────────────────────────────────────────
$browser = $_GET['browser'] ?? '';

if (!in_array($browser, ['firefox', 'chrome', 'safari'], true)) {
    http_response_code(400);
    exit('Ungültiger Browser-Parameter.');
}

if (empty($_SESSION['sync_result']['merged'])) {
    http_response_code(400);
    exit('Keine synchronisierten Lesezeichen in der Sitzung vorhanden. Bitte zuerst Dateien hochladen.');
}

$merged = $_SESSION['sync_result']['merged'];

// ── Generate export ──────────────────────────────────────────────────────────
try {
    $export = BookmarkExporter::export($merged, $browser);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Exportfehler: ' . htmlspecialchars($e->getMessage()));
}

// ── Send file ────────────────────────────────────────────────────────────────
header('Content-Type: ' . $export['mime'] . '; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
header('Content-Length: ' . strlen($export['content']));
header('Cache-Control: no-store');

echo $export['content'];
