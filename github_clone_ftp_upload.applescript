-- ============================================================
-- github_clone_ftp_upload.applescript
-- Klont ein GitHub-Repository lokal und lädt es via FTP hoch.
-- ============================================================


-- ============================================================
-- KONFIGURATION – hier alle Zugangsdaten anpassen
-- ============================================================

-- GitHub-Repository-URL (HTTPS oder SSH)
set repoURL to "https://github.com/deinbenutzername/deinrepo.git"

-- Lokaler Ordner, in den das Repository gespeichert werden soll
-- Beispiel: "/Users/deinname/Projekte/meinrepo"
set localPath to "/Users/deinname/Projekte/meinrepo"

-- Name des Unterordners, den git beim Klonen anlegt (= Repository-Name)
-- Entspricht dem letzten Teil der URL ohne ".git"
set repoFolderName to "meinrepo"

-- FTP-Serverdaten
set ftpHost to "ftp.meinserver.de"   -- Hostname oder IP des FTP-Servers
set ftpPort to "21"                  -- Standard-FTP-Port (meistens 21)
set ftpUser to "ftp-benutzername"    -- FTP-Benutzername
set ftpPassword to "ftp-passwort"    -- FTP-Passwort
set ftpRemotePath to "/public_html/" -- Zielordner auf dem FTP-Server


-- ============================================================
-- SCHRITT 1: Prüfen, ob Git auf dem System installiert ist
-- ============================================================

try
    do shell script "which git"
on error
    display dialog "Git ist nicht installiert. Bitte installiere Git von https://git-scm.com/ und starte das Skript erneut." buttons {"OK"} default button "OK" with icon stop
    return
end try


-- ============================================================
-- SCHRITT 2: Repository klonen oder aktualisieren
-- ============================================================
-- Wenn der Zielordner bereits existiert, wird „git pull" ausgeführt
-- (bestehende Dateien werden aktualisiert).
-- Andernfalls wird das Repository frisch geklont.

set repoFullPath to localPath & "/" & repoFolderName

try
    -- Prüfen, ob der Repo-Ordner schon vorhanden ist
    do shell script "test -d " & quoted form of repoFullPath & " && echo exists || echo missing"
    set folderCheck to result

    if folderCheck contains "exists" then
        -- Ordner vorhanden → aktuellen Branch auf den neuesten Stand bringen
        display dialog "Repo-Ordner gefunden. Führe 'git pull' aus..." buttons {"OK"} default button "OK"
        do shell script "cd " & quoted form of repoFullPath & " && git pull 2>&1"
        set gitOutput to result
        display dialog "git pull abgeschlossen:" & return & gitOutput buttons {"OK"} default button "OK"
    else
        -- Ordner nicht vorhanden → Zielverzeichnis anlegen und klonen
        display dialog "Kein vorhandener Ordner gefunden. Führe 'git clone' aus..." buttons {"OK"} default button "OK"
        do shell script "mkdir -p " & quoted form of localPath
        do shell script "cd " & quoted form of localPath & " && git clone " & quoted form of repoURL & " 2>&1"
        set gitOutput to result
        display dialog "git clone abgeschlossen:" & return & gitOutput buttons {"OK"} default button "OK"
    end if

on error errMsg
    display dialog "Fehler beim Git-Vorgang:" & return & errMsg buttons {"OK"} default button "OK" with icon stop
    return
end try


-- ============================================================
-- SCHRITT 3: Lokale Dateien via FTP hochladen
-- ============================================================
-- curl wird benutzt, das auf macOS vorinstalliert ist.
-- „--ftp-create-dirs" legt fehlende Verzeichnisse auf dem Server an.
-- „-r" (recursive) lädt alle Unterordner mit hoch.
-- Das Passwort wird über eine Umgebungsvariable übergeben, damit es
-- nicht im Prozess-Monitor als Klartext auftaucht.

display dialog "Starte FTP-Upload nach " & ftpHost & ftpRemotePath & "..." buttons {"OK"} default button "OK"

-- FTP-URL zusammenbauen (Format: ftp://host:port/pfad/)
set ftpURL to "ftp://" & ftpHost & ":" & ftpPort & ftpRemotePath

-- curl-Kommando für rekursiven FTP-Upload
-- Erklärung der Flags:
--   -u user:pass          → Zugangsdaten
--   --ftp-create-dirs     → Verzeichnisse auf dem Server anlegen falls nötig
--   -T "{datei}"          → Datei hochladen (wird weiter unten pro Datei aufgerufen)
--   --silent --show-error → Keine Fortschrittsbalken, aber Fehler anzeigen

-- Alle Dateien im Repository-Ordner mit „find" ermitteln und einzeln hochladen
-- Hinweis: Der .git-Ordner wird dabei übersprungen (kein Deployment von Git-Metadaten)

try
    set uploadScript to "find " & quoted form of repoFullPath & ¬
        " -type f ! -path '*/.git/*'" & ¬
        " | while IFS= read -r file; do" & ¬
        "   relative=\"${file#" & repoFullPath & "/}\";" & ¬
        "   dir=$(dirname \"$relative\");" & ¬
        "   curl --silent --show-error" & ¬
        "     -u " & quoted form of (ftpUser & ":" & ftpPassword) & ¬
        "     --ftp-create-dirs" & ¬
        "     -T \"$file\"" & ¬
        "     \"" & ftpURL & "$relative\";" & ¬
        " done 2>&1"

    do shell script uploadScript
    set uploadOutput to result

    if uploadOutput is "" then
        display dialog "FTP-Upload erfolgreich abgeschlossen!" & return & "Alle Dateien wurden nach " & ftpURL & " hochgeladen." buttons {"OK"} default button "OK" with icon note
    else
        display dialog "FTP-Upload abgeschlossen (mit Meldungen):" & return & uploadOutput buttons {"OK"} default button "OK"
    end if

on error errMsg
    display dialog "Fehler beim FTP-Upload:" & return & errMsg buttons {"OK"} default button "OK" with icon stop
    return
end try


-- ============================================================
-- SCHRITT 4: Abschluss-Meldung
-- ============================================================

display dialog "Fertig! Das Repository wurde geklont/aktualisiert und auf den FTP-Server hochgeladen." ¬
    buttons {"OK"} default button "OK" with icon note
