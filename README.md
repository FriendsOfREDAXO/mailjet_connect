# Mailjet Connect

Mailjet Connect ist ein REDAXO-AddOn für den Empfang und die Auswertung von Mailjet Webhook-Events.

## Funktionen

- Webhook-Endpunkt für Mailjet (`rex-api-call=mailjet_connect_webhook`)
- Speicherung eingehender Events in einer eigenen Tabelle
- Ereignisliste im REDAXO-Backend mit Filter, Sortierung und aufklappbaren Rohdaten
- Pro Event: Sync-Status mit Versuchsanzahl und Zeitstempel
- Manuelle Nachhol-Aktion für fehlgeschlagene oder vorgemerkte Syncs
- Diagnose-Seite mit API-Test und lokalem Testsender für Beispiel-Events
- Optionale YForm-Synchronisation:
  - Datensätze deaktivieren oder löschen
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
- **Token**: Token-Feld befüllen – Token kann per URL-Parameter, Header `X-Webhook-Token` oder Bearer-Token übergeben werden
- **Ohne Auth**: Alle Felder leer lassen

### Validierung durch Mailjet

Mailjet prüft die URL vor dem Eintragen per GET-Request. Der Webhook antwortet darauf mit HTTP 200. Für Produktionsinstallationen ist HTTPS zwingend.

## YForm-Synchronisation

Konfiguration unter **Mailjet Connect > YForm-Synchronisation**:

| Feld | Beschreibung |
|---|---|
| Ziel-YForm-Tabelle | Tabellenname inkl. Prefix, z. B. `rex_newsletter_subscribers` |
| E-Mail-Feld | Spaltenname mit der E-Mail-Adresse |
| Aktion | Deaktivieren (Status-Feld setzen) oder Löschen |
| Ausführung | Sofort beim Webhook oder nur vormerken (Cronjob) |
| Ereignistypen | Kommagetrennte Liste, z. B. `blocked,spam,bounce` |

### Welche Events lösen einen Sync aus?

| Event | Bedingung | Empfehlung |
|---|---|---|
| `blocked` | immer | ✔ Standard |
| `spam` | immer | ✔ Standard |
| `bounce` | nur wenn `hard_bounce=true` | ✔ Soft Bounces werden ignoriert |

Empfohlener Einstieg: `blocked,spam,bounce`

## Sync-Status

Jedes gespeicherte Event zeigt in der Ereignisliste einen Status:

| Status | Bedeutung |
|---|---|
| **Synchronisiert** | YForm-Abgleich erfolgreich |
| **Vorgemerkt** | Wartet auf manuellen Lauf oder Cronjob |
| **Fehlgeschlagen** | Sync wurde versucht, schlug fehl |
| **Nicht nötig** | Kein Sync erforderlich (z. B. Soft Bounce) |
| **Unbekannt** | Altbestand, noch nicht bewertet |

Über **Syncs jetzt verarbeiten** in der Ereignisliste können vorgemerkte und fehlgeschlagene Events manuell nachgeholt werden.

## Cronjob

Wenn der Modus **Nur vormerken und per Cronjob verarbeiten** aktiv ist, werden passende Events beim Webhook-Eingang nur gespeichert und als `vorgemerkt` markiert.

Zur Verarbeitung:
- manuell über **Syncs jetzt verarbeiten** in der Ereignisliste
- automatisch über den REDAXO-Cronjob **Mailjet Connect: ausstehende Syncs verarbeiten**

## Diagnose

Unter **Mailjet Connect > Diagnose** stehen drei Tools bereit:

1. **Mailjet API testen** – Verbindung zu `api.mailjet.com` mit den gespeicherten Zugangsdaten prüfen
2. **Webhook-URL** – Aktuelle URL zum Kopieren und Eintragen in Mailjet
3. **Testdaten senden** – Beispiel-Events direkt durch Parser, Speicherung und Sync leiten, ohne Netzwerkanfrage. Wählbar: Eventtyp (Hard Bounce, Blocked, Spam) und Transportform (Einzel-Event oder Batch).

## Hinweise

- Die Event-Tabelle wird beim Installieren automatisch angelegt und bei Bedarf um neue Spalten ergänzt.
- Rohdaten jedes Events sind in der Ereignisliste per Klick aufklappbar.
- Das AddOn ist ohne YForm nutzbar; YForm-Sync ist komplett optional.

## Lizenz

Dieses Projekt steht unter der MIT-Lizenz. Siehe [LICENSE.md](LICENSE.md).


## Funktionen

- Webhook-Endpunkt fuer Mailjet (`rex-api-call=mailjet_connect_webhook`)
- Speicherung eingehender Events in einer eigenen Tabelle
- Ereignisliste im REDAXO-Backend mit Filter und Sortierung
- Diagnose-Seite fuer Verbindungs-Checks
- Optionale YForm-Synchronisation auf eigener Unterseite:
  - Datensaetze deaktivieren
  - Datensaetze loeschen

## Voraussetzungen

- REDAXO `>= 5.17.0`
- PHP `>= 8.2`

## Installation

1. AddOn in den Ordner `redaxo/src/addons/mailjet_connect` legen.
2. Im REDAXO-Backend installieren.
3. Unter Mailjet Connect > Einstellungen Webhook-Daten pflegen.
4. Webhook-URL in Mailjet hinterlegen.

## Webhook

Die Webhook-URL wird im Backend angezeigt. Typisch:

`https://example.org/index.php?rex-api-call=mailjet_connect_webhook`

Optional kann Basic Auth fuer den Webhook gesetzt werden.

## YForm-Synchronisation

Die Konfiguration liegt unter:

Mailjet Connect > YForm-Synchronisation

Dort kannst du festlegen:

- Ziel-YForm-Tabelle
- E-Mail-Feld
- Aktion (deaktivieren oder loeschen)
- Ausfuehrung: sofort oder per Cronjob
- relevante Eventtypen

### Welche Mails werden gesynct?

Gesynct werden keine "Mails" im Sinne von Inhalten, sondern Datensaetze in deiner YForm-Tabelle anhand der E-Mail-Adresse aus dem Webhook-Event.

Standardmaessig sind als relevante Eventtypen hinterlegt:

- `bounce`
- `blocked`
- `spam`

Wichtig:

- `bounce` kann auch temporaer sein (Soft Bounce).
- Bei `bounce` synchronisiert das AddOn nur dann, wenn `hard_bounce=true` ist.
- Soft Bounces (`hard_bounce=false`) werden fuer den YForm-Sync ignoriert.

Empfehlung fuer viele Projekte:

- konservativ: nur `blocked,spam` synchronisieren
- strenger: `bounce,blocked,spam` verwenden

Wenn du unsicher bist, starte mit `blocked,spam` und beobachte die Rohdaten in der Ereignisliste.

## Wann wird gesynct?

Der YForm-Sync laeuft direkt beim Eingang eines Webhook-Events oder optional zeitversetzt per Cronjob.

Ein Event wird nur dann synchronisiert, wenn alle Bedingungen erfuellt sind:

- YForm-Synchronisation ist aktiviert
- YForm ist verfuegbar
- YForm-Tabelle und Felder sind korrekt konfiguriert
- das Event enthaelt eine E-Mail-Adresse
- der Eventtyp ist fuer die Synchronisation freigegeben
- bei `bounce` gilt zusaetzlich: nur `hard_bounce=true`

## Sync-Status in der Ereignisliste

Jedes Event erhaelt einen Status, damit nachvollziehbar bleibt, ob und wie es verarbeitet wurde:

- `Synchronisiert`: Der YForm-Abgleich wurde erfolgreich ausgefuehrt.
- `Vorgemerkt`: Das Event wartet auf einen manuellen Lauf oder Cronjob.
- `Fehlgeschlagen`: Der Sync wurde versucht, konnte aber nicht abgeschlossen werden.
- `Nicht noetig`: Fuer dieses Event war kein Sync erforderlich, zum Beispiel bei Soft Bounces oder nicht freigegebenen Eventtypen.
- `Unbekannt`: Altbestand oder noch nicht bewerteter Eintrag.

Fehlgeschlagene oder vorgemerkte Syncs koennen in der Ereignisliste manuell erneut verarbeitet werden.

## Cronjob-Modus

Wenn im Bereich YForm-Synchronisation der Modus `Nur vormerken und per Cronjob verarbeiten` gewaehlt ist, werden passende Events beim Webhook-Eingang nicht sofort synchronisiert, sondern als vorgemerkt gespeichert.

Danach gibt es zwei Wege:

- manuell ueber die Aktion `Syncs jetzt verarbeiten` in der Ereignisliste
- automatisch ueber einen REDAXO-Cronjob vom Typ `Mailjet Connect: ausstehende Syncs verarbeiten`

## Hinweise

- Die Event-Tabelle wird beim Installieren/Bootstrapping automatisch sichergestellt.
- Bei aktiver Synchronisation werden passende Datensaetze direkt beim Webhook-Eingang verarbeitet.
- Auf der Diagnose-Seite kann ein Beispiel-Event im bekannten Mailjet-Schema direkt an den konfigurierten Webhook gesendet werden.
- Der Testsender unterstuetzt sowohl ein einzelnes Event als auch einen Batch mit Wrapper, damit auch gebuendelte Payloads geprueft werden koennen.

## Lizenz

Dieses Projekt steht unter der MIT-Lizenz. Siehe [LICENSE.md](LICENSE.md).
