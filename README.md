# iCAL2RSS

Kleine PHP-Bridge ohne Datenbank. Sie lädt öffentliche iCal-/ICS-Kalender und schreibt statische RSS-Feeds für Fever, Reeder und andere Reader, die kein iCal verstehen.

Wenn eine Seite bereits einen Event-RSS anbietet, den direkt abonnieren. Diese Bridge ist für Kalender, die nur `.ics` oder `webcal://` haben.

## Voraussetzungen

- PHP 8.2 oder neuer, mit JSON und entweder cURL oder `allow_url_fopen`
- Ein Webspace, auf dem `data/` und `feeds/` beschreibbar sind
- Ein Cronjob für `cron.php`

Kein Composer, keine Datenbank. Wiederkehrende Termine werden nicht aus `RRULE` expandiert. Es zählen die `VEVENT`s, die in der Datei stehen.

## Verzeichnis

```text
config.php                   Standardwerte, ohne Geheimnisse
config.local.php.example     Vorlage für die lokale Config
sources.json                 Kalender
cron.php                     holt alle Kalender und schreibt die Feeds
index.php                    Status und manueller Lauf
lib.php                      Abruf, VEVENT-Parser, RSS
data/status.json             letzter Lauf, nicht im Git
feeds/{id}.xml               erzeugte Feeds, nicht im Git
```

## Einrichtung

1. Den Ordner auf den Webspace legen.
2. `data/` und `feeds/` beschreibbar machen (`chmod 775`).
3. `config.local.php.example` nach `config.local.php` kopieren und `base_url` setzen, ohne Slash am Ende. Beispiel: `https://example.com/ical-rss`.
4. Kalender in `sources.json` eintragen.
5. Einmal laufen lassen: `php cron.php`, oder auf der Statusseite „Jetzt aktualisieren“.
6. In Fever `{base_url}/feeds/{id}.xml` eintragen.
7. Cron, zum Beispiel täglich:

```cron
15 6 * * * php /pfad/zum/ical-rss/cron.php
```

`config.local.php` bleibt auf dem Server. Keine Hosting-Zugänge und keine Kalender-Passwörter hinein schreiben. Einen optionalen `cron_key` nur dort setzen.

## Kalender

```json
[
  {
    "id": "beispiel",
    "name": "Beispieltermine",
    "ical_url": "https://example.com/termine/?ical=1",
    "site_url": "https://example.com/kalender/"
  }
]
```

`ical_url` muss öffentlich per `https://`, `http://` oder `webcal://` erreichbar sein. `webcal://` wird zu `https://`. `site_url` ist der Fallback-Link, wenn ein Termin keine eigene URL hat.

Die mitgelieferte Quelle ist der öffentliche Terminkalender von dieMotive, zum Ausprobieren. Deren nativer Feed `termine/feed/` bleibt die bessere Wahl, sobald er existiert.

## Verhalten

Der Abruf liest `SUMMARY`, `DTSTART`, `DTEND`, `LOCATION`, `DESCRIPTION`, `URL` und `UID`. Ganztags- und Uhrzeittermine werden erkannt. Eine Zeitzonenangabe (`TZID`) wird grob über die konfigurierte Zeitzone gelesen, Voreinstellung `Europe/Berlin`.

Im Feed landen Termine von 7 Tagen in der Vergangenheit bis 180 Tage in der Zukunft (`past_days`, `future_days`). Sortierung ist nach Beginn. Höchstens 100 Einträge (`feed_item_limit`).

Ein RSS-Eintrag:

| Feld | Inhalt |
|---|---|
| `title` | `SUMMARY` |
| `link` | `URL`, sonst die Site oder die ICS-Adresse |
| `guid` | `UID`, sonst ein Hash aus Titel und Beginn |
| `pubDate` | `DTSTART` |
| Beschreibung | Datum, Ort, Kurztext |

## Betrieb

```text
php cron.php
```

Die CLI druckt pro Kalender `OK` oder `FAIL` und endet mit Code 0, wenn jeder Abruf geklappt hat.

Per HTTP antwortet `cron.php` mit JSON. Ist `cron_key` gesetzt, braucht der Aufruf `?key=…`, sonst 403. Die Statusseite listet die Quellen, den letzten Lauf und die Feed-URLs.

## Was nicht ins Git gehört

- `config.local.php`
- `data/status.json`
- erzeugte `feeds/*.xml`
- Hosting-Zugänge, `cron_key`, nicht öffentliche Kalender-URLs mit Zugangsdaten
