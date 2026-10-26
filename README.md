# Sync ResellerInterface

Ein Laravel Zero CLI-Tool zur Synchronisierung von DNS-Records zwischen ResellerInterface und Plesk.

## Beschreibung

Dieses Tool synchronisiert DNS-Records von ResellerInterface zu Plesk. Es liest DNS-Einträge aus der ResellerInterface API und aktualisiert die entsprechenden Domains in Plesk automatisch.

### Features

- Synchronisation aller Domains oder einzelner Domains
- Dry-Run Modus zum Testen ohne Änderungen
- Automatisches Deaktivieren/Aktivieren der ResellerInterface DNS-Extension (verhindert Sync-Loops)
- Detaillierte Übersicht über hinzugefügte, gelöschte und fehlerhafte Records
- Interaktive Domain-Suche mit detaillierter Ansicht aller Änderungen
- Automatisches Rollback bei Fehlern
- NS-Records werden übersprungen (sollten nicht synchronisiert werden)
- Unterstützt alle gängigen DNS-Record-Typen: A, AAAA, CNAME, MX, TXT, NS, SRV, PTR

## Installation

```bash
composer install
```

## Konfiguration

Kopiere die `.env.example` Datei und benenne sie in `.env` um:

```bash
cp .env.example .env
```

Fülle die folgenden Umgebungsvariablen aus:

### Plesk Konfiguration

```env
PLESK_URL=https://your-plesk-server.com:8443
PLESK_USERNAME=admin
PLESK_PASSWORD=your-password
# Oder verwende einen API Key:
PLESK_API_KEY=your-api-key
```

### ResellerInterface Konfiguration

```env
RESELLERINTERFACE_USERNAME=your-username
RESELLERINTERFACE_PASSWORD=your-password
RESELLERINTERFACE_RESELLER_ID=your-reseller-id
```

## Verwendung

### Alle Domains synchronisieren

```bash
php sync-reseller-interface sync:reseller-to-plesk
```

### Einzelne Domain synchronisieren

```bash
php sync-reseller-interface sync:reseller-to-plesk --domain=example.com
```

### Dry-Run (nur Vorschau ohne Änderungen)

```bash
php sync-reseller-interface sync:reseller-to-plesk --dry-run
php sync-reseller-interface sync:reseller-to-plesk --domain=example.com --dry-run
```

## Funktionsweise

1. **Verbindungstest**: Prüft die Verbindung zu Plesk und ResellerInterface
2. **Domains abrufen**: Lädt alle Domains aus ResellerInterface
3. **Extension deaktivieren**: Deaktiviert temporär die ResellerInterface DNS-Extension in Plesk (verhindert Sync-Loops)
4. **DNS-Records synchronisieren**:
   - Liest alle DNS-Records aus ResellerInterface
   - Löscht bestehende Records in Plesk (außer NS-Records)
   - Erstellt neue Records basierend auf ResellerInterface-Daten
   - Bei Fehlern: Automatisches Rollback zu den ursprünglichen Records
5. **Extension aktivieren**: Aktiviert die ResellerInterface DNS-Extension wieder
6. **Interaktive Übersicht**: Durchsuchbare Domain-Liste mit detaillierten Informationen zu allen Änderungen

## Ausgabe

Das Tool zeigt eine übersichtliche Zusammenfassung aller synchronisierten Domains:

- Erfolgreiche Synchronisationen
- Fehlgeschlagene Synchronisationen
- Übersprungene Domains (nicht in Plesk gefunden)

Nach der Synchronisation kann in einer interaktiven Suche jede Domain ausgewählt werden, um Details zu sehen:

- DNS-Records aus ResellerInterface
- Ursprüngliche DNS-Records in Plesk
- Gelöschte Records
- Hinzugefügte Records
- Fehlgeschlagene Records
- Statistiken (Anzahl hinzugefügter/gelöschter/fehlgeschlagener Records)

## Wichtige Hinweise

- NS-Records werden automatisch übersprungen und nicht synchronisiert
- Bei Fehlern während der Synchronisation erfolgt ein automatisches Rollback
- Die ResellerInterface DNS-Extension wird temporär deaktiviert, um Sync-Loops zu verhindern
- Im Dry-Run Modus werden keine Änderungen vorgenommen

## Anforderungen

- PHP 8.4 oder höher
- Composer
- Zugriff auf Plesk Server (via API)
- Zugriff auf ResellerInterface API

## Lizenz

MIT License