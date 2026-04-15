-- ============================================================
-- github_download_ftp_upload.applescript
-- Lädt ein GitHub-Repository als ZIP herunter, entpackt es
-- im Downloads-Ordner und lädt den ganzen Inhalt via FTP hoch.
-- Kein Git erforderlich.
-- ============================================================


-- ============================================================
-- KONFIGURATION – hier alle Werte anpassen
-- ============================================================

-- GitHub ZIP-Download-URL des gewünschten Branches
-- Format: https://github.com/<user>/<repo>/archive/refs/heads/<branch>.zip
set zipURL to "https://github.com/greatkickoff/bms/archive/refs/heads/claude/bookmark-sync-website-JebqA.zip"

-- Lokaler Speicherort für die ZIP-Datei (Downloads-Ordner des Benutzers)
set downloadsFolder to (path to downloads folder as string)
set downloadsFolderPOSIX to POSIX path of downloadsFolder
-- Dateiname der ZIP, die curl speichern soll
set zipFileName to "github-repo.zip"
set zipFilePath to downloadsFolderPOSIX & zipFileName

-- FTP-Serverdaten
set ftpHost to "ftp.meinserver.de"   -- Hostname oder IP des FTP-Servers
set ftpPort to "21"                  -- Standard-FTP-Port (meistens 21)
set ftpUser to "ftp-benutzername"    -- FTP-Benutzername
set ftpPassword to "ftp-passwort"    -- FTP-Passwort
set ftpRemotePath to "/public_html/" -- Zielordner auf dem FTP-Server (mit Schrägstrich am Ende)


-- ============================================================
-- SCHRITT 1: ZIP-Datei von GitHub herunterladen
-- ============================================================
-- curl lädt die ZIP direkt aus der GitHub-URL herunter.
-- Flags:
--   -L  → folgt Weiterleitungen (GitHub leitet intern um)
--   -o  → Ausgabedatei festlegen
--   --fail → bricht bei HTTP-Fehlern (z. B. 404) ab statt leere Datei zu speichern

display dialog "Lade Repository-ZIP von GitHub herunter…" & return & zipURL buttons {"OK"} default button "OK"

try
    do shell script "curl -L --fail --silent --show-error" & ¬
        " -o " & quoted form of zipFilePath & ¬
        " " & quoted form of zipURL & ¬
        " 2>&1"
on error errMsg
    display dialog "Fehler beim Download:" & return & errMsg buttons {"OK"} default button "OK" with icon stop
    return
end try

display dialog "Download abgeschlossen:" & return & zipFilePath buttons {"OK"} default button "OK"


-- ============================================================
-- SCHRITT 2: ZIP im Downloads-Ordner entpacken
-- ============================================================
-- „unzip" ist auf macOS vorinstalliert.
-- Flags:
--   -o  → vorhandene Dateien ohne Rückfrage überschreiben
--   -d  → Zielordner angeben (= Downloads-Ordner)
-- GitHub packt alles in einen Unterordner wie „reponame-branchname",
-- dieser wird automatisch im Downloads-Ordner erstellt.

display dialog "Entpacke ZIP-Datei in:" & return & downloadsFolderPOSIX buttons {"OK"} default button "OK"

try
    do shell script "unzip -o " & quoted form of zipFilePath & ¬
        " -d " & quoted form of downloadsFolderPOSIX & ¬
        " 2>&1"
    set unzipOutput to result
on error errMsg
    display dialog "Fehler beim Entpacken:" & return & errMsg buttons {"OK"} default button "OK" with icon stop
    return
end try


-- ============================================================
-- SCHRITT 3: Entpackten Ordnernamen ermitteln
-- ============================================================
-- GitHub benennt den Ordner innerhalb der ZIP immer nach dem Schema
-- „<reponame>-<branchname>". Wir lesen den tatsächlichen Namen aus,
-- anstatt ihn hart zu kodieren.

try
    -- Oberstes Verzeichnis in der ZIP auslesen (erste Zeile nach Header)
    do shell script "unzip -Z1 " & quoted form of zipFilePath & " | head -1 | cut -d'/' -f1"
    set extractedFolderName to result
    set extractedFolderPath to downloadsFolderPOSIX & extractedFolderName
on error errMsg
    display dialog "Fehler beim Ermitteln des Ordnernamens:" & return & errMsg buttons {"OK"} default button "OK" with icon stop
    return
end try

display dialog "Entpackter Ordner:" & return & extractedFolderPath buttons {"OK"} default button "OK"


-- ============================================================
-- SCHRITT 4: Gesamten Ordner via FTP hochladen
-- ============================================================
-- curl lädt jede Datei einzeln hoch und erhält dabei die Ordnerstruktur.
-- „find" listet alle Dateien rekursiv auf.
-- Der relative Pfad (ohne den lokalen Prefix) wird als FTP-Pfad verwendet,
-- damit die Ordnerstruktur auf dem Server identisch ist.
-- „--ftp-create-dirs" legt fehlende Unterordner auf dem Server automatisch an.

set ftpURL to "ftp://" & ftpHost & ":" & ftpPort & ftpRemotePath

display dialog "Starte FTP-Upload nach:" & return & ftpURL & return & return & "Quelle: " & extractedFolderPath buttons {"OK"} default button "OK"

try
    -- Shell-Skript: alle Dateien im entpackten Ordner finden und einzeln hochladen
    -- ${file#<prefix>/} schneidet den lokalen Pfad-Prefix ab → relativer Pfad
    set uploadScript to "find " & quoted form of extractedFolderPath & ¬
        " -type f" & ¬
        " | while IFS= read -r file; do" & ¬
        "   relative=\"${file#" & extractedFolderPath & "/}\";" & ¬
        "   curl --silent --show-error" & ¬
        "     -u " & quoted form of (ftpUser & ":" & ftpPassword) & ¬
        "     --ftp-create-dirs" & ¬
        "     -T \"$file\"" & ¬
        "     \"" & ftpURL & "$relative\";" & ¬
        " done 2>&1"

    do shell script uploadScript
    set uploadOutput to result

    if uploadOutput is "" then
        display dialog "FTP-Upload erfolgreich abgeschlossen!" & return & ¬
            "Alle Dateien wurden nach " & ftpURL & " hochgeladen." ¬
            buttons {"OK"} default button "OK" with icon note
    else
        display dialog "FTP-Upload abgeschlossen (mit Meldungen):" & return & uploadOutput ¬
            buttons {"OK"} default button "OK"
    end if

on error errMsg
    display dialog "Fehler beim FTP-Upload:" & return & errMsg buttons {"OK"} default button "OK" with icon stop
    return
end try


-- ============================================================
-- SCHRITT 5: Abschluss-Meldung
-- ============================================================

display dialog "Fertig!" & return & return & ¬
    "✓ ZIP heruntergeladen: " & zipFilePath & return & ¬
    "✓ Entpackt in: " & extractedFolderPath & return & ¬
    "✓ Hochgeladen nach: " & ftpURL ¬
    buttons {"OK"} default button "OK" with icon note
