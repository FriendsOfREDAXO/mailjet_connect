# Mailjet Connect

Mailjet Connect ist ein REDAXO-AddOn für den Empfang und die Auswertung von Mailjet Webhook-Events.

## Funktionen

- Webhook-Endpunkt für Mailjet (`rex-api-call=mailjet_connect_webhook`)
- Speicherung eingehender Events in einer eigenen Tabelle
- Ereignisliste im REDAXO-Backend mit Filter, Sortierung und aufklappbaren Rohdaten
- Pro Event: Sync-Status mit Versuchsanzahl und Zeitstempel
- Manuelle Nachhol-Aktion für fehlgeschlagene oder vorgemerkte Syncs
- Optionale manuelle Einzel-Synchronisation von Spam-Events aus der Ereignisliste (z. B. bei Spam-Modus „nur protokollieren“)
- Diagnose-Seite mit API-Test und lokalem Testsender für Beispiel-Events
- Optionale YForm-Synchronisation:
  - Datensätze deaktivieren oder löschen
  - Optionales Begründungsfeld inkl. Template-Platzhaltern
  - Sofort-Modus oder Cronjob-Modus

## Voraussetzungen

- REDAXO `>= 5.17.0`
- PHP `>= 8.2`

## Installation

1. AddOn in den Ordner `redaxo/src/addons/mailjet_connect` legen.
2. Im REDAXO-Backend installieren.
3. Unter **Mailjet Connect > Einstellungen** API-Zugangsdaten und Webhook konfigurieren.
4. Webhook-URL in Mailjet hinterlegen.

## Webhook

Die Webhook-URL wird im Backend unter **Einstellungen** angezeigt. Typisch:

```
https://example.org/index.php?rex-api-call=mailjet_connect_webhook
```

### Authentifizierung

Drei Varianten sind möglich (oder komplett ohne Auth):

- **Basic Auth**: Benutzername und Passwort in den Einstellungen setzen
- **Token**: Token-Feld befüllen. Übergabe möglich per URL-Parameter, Header `X-Webhook-Token` oder Bearer-Token
- **Ohne Auth**: Alle Felder leer lassen

### Validierung durch Mailjet

Mailjet prüft die URL vor dem Eintragen per GET-Request. Der Webhook antwortet darauf mit HTTP 200. Für Produktionsinstallationen ist HTTPS zwingend.

## YForm-Synchronisation

Konfiguration unter **Mailjet Connect > YForm-Synchronisation**.

| Feld | Beschreibung |
|---|---|
| Ziel-YForm-Tabelle | Auswahl aus vorhandenen YForm-Tabellen |
| E-Mail-Feld | Auswahl aus Spalten der gewählten Tabelle |
| Aktion | Deaktivieren (Status-Feld setzen) oder Löschen |
| Status-Feld | Auswahl aus Spalten der gewählten Tabelle |
| Optionales Begründungsfeld | Auswahl aus Spalten der gewählten Tabelle |
| Begründungs-Template | Platzhalter: `{event_type}`, `{email}`, `{error_message}`, `{date}` |
| Ausführung | Sofort beim Webhook oder nur vormerken (Cronjob) |
| Ereignistypen | Kommagetrennte Liste, z. B. `blocked,spam,bounce` |
| Spam-Meldungen | „Synchronisieren“ oder „Nur protokollieren“ |

### Welche Events lösen einen Sync aus?

| Event | Bedingung | Empfehlung |
|---|---|---|
| `blocked` | immer | ✔ Standard |
| `spam` | immer (außer Spam-Modus „nur protokollieren“) | ✔ Standard |
| `bounce` | nur wenn `hard_bounce=true` | ✔ Soft Bounces werden ignoriert |

Empfohlener Einstieg: `blocked,spam,bounce`

## Sync-Status

Jedes gespeicherte Event zeigt in der Ereignisliste einen Status:

| Status | Bedeutung |
|---|---|
| **Synchronisiert** | YForm-Abgleich erfolgreich |
| **Vorgemerkt** | Wartet auf manuellen Lauf oder Cronjob |
| **Fehlgeschlagen** | Sync wurde versucht, schlug fehl |
| **Nicht nötig** | Kein Sync erforderlich (z. B. Soft Bounce, Event nicht ausgewählt oder Spam „nur protokollieren“) |
| **Unbekannt** | Altbestand, noch nicht bewertet |

### Manuelle Aktionen in der Ereignisliste

- **Syncs jetzt verarbeiten**: vorgemerkte und fehlgeschlagene Events neu verarbeiten
- **Jetzt deaktivieren**: einzelne Spam-Events im Modus „nur protokollieren“ gezielt manuell synchronisieren
- **Ereignisliste leeren**: gesamte Tabelle per Button löschen (mit Sicherheitsabfrage)

## Cronjob

Wenn der Modus **Nur vormerken und per Cronjob verarbeiten** aktiv ist, werden passende Events beim Webhook-Eingang als `vorgemerkt` gespeichert.

Verarbeitung:

- manuell über **Syncs jetzt verarbeiten** in der Ereignisliste
- automatisch über den REDAXO-Cronjob **Mailjet Connect: ausstehende Syncs verarbeiten**

Zusätzlich bereinigt der Cronjob alte Rohdaten (`payload_json`) anhand der konfigurierten Aufbewahrungsdauer.

## Diagnose

Unter **Mailjet Connect > Diagnose** stehen drei Tools bereit:

1. **Mailjet API testen**: Verbindung zu `api.mailjet.com` mit den gespeicherten Zugangsdaten prüfen
2. **Webhook-URL**: aktuelle URL zum Kopieren und Eintragen in Mailjet
3. **Testdaten senden**: Beispiel-Events direkt durch Parser, Speicherung und Sync leiten, ohne Netzwerkanfrage (Eventtyp + Transportform wählbar)

## Hinweise

- Die Event-Tabelle wird beim Installieren automatisch angelegt und bei Bedarf um neue Spalten ergänzt.
- Rohdaten jedes Events sind in der Ereignisliste per Klick aufklappbar.
- Das AddOn ist ohne YForm nutzbar; YForm-Sync ist komplett optional.

## Credits

- Friends Of REDAXO

## Lizenz

Dieses Projekt steht unter der MIT-Lizenz. Siehe [LICENSE.md](LICENSE.md).
