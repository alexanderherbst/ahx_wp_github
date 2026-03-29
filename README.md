# AHX WP GitHub

Version: v1.11.0  
Author: AHX

## Beschreibung

AHX WP GitHub ist ein WordPress-Admin-Plugin zur Verwaltung lokaler Git-Repositories im WordPress-Umfeld.

Es kann Verzeichnisse als Repositories erfassen, bei Bedarf `git init` ausfuehren, Aenderungen anzeigen, Commits/Pushes starten, Repository-Details bearbeiten und Diagnosebefehle ausfuehren.

## Hauptfunktionen

- Erfasst Verzeichnisse in einer DB-Tabelle (`wp_ahx_wp_github`)
- Initialisiert Verzeichnisse ohne `.git` automatisch als Git-Repository
- Erkennt Typ des Eintrags (`plugin`, `template`, `other`)
- Zeigt Repository-Status und Dateiaenderungen (inkl. Diff-Vorschau)
- Unterstuetzt Commit-Workflows und Push/Sync-Aktionen
- Bietet einen Workflow-Assistenten fuer Feature-Branch/Rebase-Ablauf
- Bietet Diagnose-Seite fuer Git/DNS/Remote-Pruefungen
- Stellt AJAX-Commit-Endpoint bereit (`wp_ajax_ahx_repo_commit`)
- Nutzt Timeout-geschuetzte Prozessausfuehrung fuer Git-Befehle

## Admin-Menue

Top-Level-Menue: `AHX WP GitHub` (Slug: `ahx-wp-github`)

Unterseiten:
- `Uebersicht` (`admin/admin-page.php`)
- `Einstellungen` (`admin/config-page.php`)
- `Diagnose` (`admin/diagnostics-page.php`)
- `Workflow-Assistent` (`admin/workflow-wizard-page.php`)

## Datenbank

Bei Aktivierung wird die Tabelle `{$wpdb->prefix}ahx_wp_github` angelegt:

- `id`
- `name`
- `dir_path`
- `type` (`plugin|template|other`)
- `safe_directory`
- `created_at`

## Einstellungen

Registrierte Optionen (Settings API):

- `ahx_wp_github_level_of_logging`
- `ahx_wp_github_prefer_api`
- `ahx_wp_github_git_timeout_seconds` (5-120)
- `ahx_wp_github_remote_policy` (`all` oder `github_only`)

Hinweis: Fuer Remote-Authentifizierung kann das Plugin den Token aus `ahx_wp_main_github_token` verwenden.

## Sicherheit und Robustheit

- Alle Admin-Views pruefen `manage_options`
- Nonce-Pruefung fuer Aktionen und AJAX
- Pfadnormalisierung fuer Windows/Linux
- Kommando-Redaction fuer sensitive Header in Logs
- Prozess-Timeout mit Abbruchlogik (inkl. Windows `taskkill`)

## Wichtige Dateien

- `ahx_wp_github.php` - Bootstrap, Menue, AJAX, Activation Hook
- `admin/admin-page.php` - Uebersicht, Repo-Liste, Direktaktionen
- `admin/repo-changes.php` - Datei-Liste, Diffs, Commit-Eingabe
- `admin/repo-details.php` - Detailansicht/Repository-Konfiguration
- `admin/commit-handler.php` - Git-Ausfuehrung, Commit/Push-Logik
- `admin/diagnostics-page.php` - Diagnosebefehle und Ausgaben
- `admin/workflow-wizard-page.php` - gefuehrter Git-Workflow
- `admin/settings.php` - Optionsregistrierung
- `includes/logging.php` - Logging-Helfer

## Typischer Ablauf

1. Unter `AHX WP GitHub > Uebersicht` ein Verzeichnis erfassen.
2. Falls noetig, wird das Verzeichnis automatisch als Git-Repo initialisiert.
3. In die Aenderungsansicht wechseln (`repo-changes`) und Commit vorbereiten.
4. Commit ausfuehren und per Sync/Push zum Remote uebertragen.
5. Bei Problemen die Diagnose-Seite fuer Remote/DNS/Git-Checks verwenden.

## Voraussetzungen

- WordPress mit Admin-Zugriff (`manage_options`)
- Installiertes `git` auf dem Server/Host
- Shell-Prozessausfuehrung verfuegbar (`proc_open`)

## Hinweise

- Das Plugin ist auf lokale Server-Setups (z. B. WAMP) ausgelegt.
- Fuer Remote-Pushes sollte ein gueltiger Token in den AHX-Main-Optionen hinterlegt sein.
- Timeout-Werte koennen in den Einstellungen angepasst werden, um langsame Umgebungen zu beruecksichtigen.
