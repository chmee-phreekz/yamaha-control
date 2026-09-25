# Yamaha QL1 Regie (Webapp)

**Version:** 1.1.0

Browserbasierte Steuer-/Anzeigeoberfläche für ein Yamaha QL1-Mischpult über
das netzwerkbasierte **SCP/RCP-Protokoll** (TCP, Port 49280, textbasiert).
Kein Framework, kein Dauer-Prozess – reines PHP/HTML/JS/CSS, SQLite nur als
Cache und für Presets als JSON-Dateien.

## Funktionsumfang

**Eingänge (32 Mono + 8 Stereo) und Ausgänge (16 Mix + 8 Matrix)**
- Vertikale Fader mit Audio-Taper-Kennlinie (unterer Anschlag = -unendlich,
  oberste 60 % des Wegs decken den Feinbereich -24…+12 dB ab)
- Referenzlinien im Fader: 0 dB (betont) sowie -5/-10/-20 dB (fein)
- ON-Button (leuchtet orange, invertierte Logik zum alten "Mute"), EQ- und
  DYN-Button (nur bei Ein­gängen), Kanalname mit Hintergrundfarbe passend zur
  am Pult zugewiesenen Kanalfarbe
- Layout responsiv: über 1600 px 16 Fader/Zeile, 721–1600 px 8, ab 720 px 4 –
  passt sich live beim Fenster­größe ändern an

**EQ-Modal** (pro Eingang): HPF (eigener Schalter + Frequenz), 4 Bänder
(Low/High als Shelf, Low-Mid/High-Mid parametrisch mit Q) mit gemeinsamem
EQ-Ein/Aus-Schalter für alle 4 Bänder, Eingabefelder statt Fader, Live-Kurve
als SVG.

**Dynamics-Modal** (pro Eingang): zwei Tabs – Dyna1 (Gate: Threshold, Range,
Attack, Hold, Decay) und Dyna2 (Kompressor: Threshold, Ratio, Attack,
Release, Out Gain, Knee) inkl. grafischer Kompressorkurve.

**Matrix-Routing-Tabelle**: Anzeige, welcher Mix-Bus zu welchem Matrix-Bus
geroutet ist (On + Pegel) – aktuell nur lesend, siehe „Bekannte Lücken".

**Presets**: Button im Header, 8 Plätze (`preset_count` in `config.php`).
Jedes Preset ist ein Voll-Snapshot (Fader, Namen, Farben, Mute/On, komplettes
Routing, EQ/HPF und Dynamics aller Eingänge), gespeichert als
`data/presets/preset_<slot>.json` – damit einfach als Datei sicherbar/
austauschbar. Speichern fragt vorher noch mal nach (Bestätigungsdialog).

**Debug-Werkzeuge** (Header-Button, dezent eingefärbt, rot umrandetes Modal):
ein Info-Fenster mit drei Beispielen für die externe API (SET Input-Volume,
SET Input-Name, GET Out-Volume – siehe unten), der Demo-Modus (Sinuswelle
über die ersten 16 Mono-Fader, phasenversetzt, 360°/10 s, sichert/stellt
automatisch die Ursprungswerte wieder her) sowie eine freie SCP/RCP-
Befehlskonsole mit Antwortfenster.

**Log** (Header-Button): zeigt die letzten 24 tatsächlichen Aktionen dieser
Sitzung (Fader/ON, EQ, Dynamics, Konsolenbefehle, Preset-Aktionen, Demo) –
reines Status-Polling wird nicht mitgeloggt.

## Setup

1. `config.php` öffnen und `mixer_ip` auf die IP-Adresse deines QL1 setzen.
   Der Port (49280) ist Werksstandard und muss normalerweise nicht verändert
   werden.
2. Ordner auf einen PHP-fähigen Webserver legen (PHP 8.0+, `pdo_sqlite`-
   Extension aktiviert – bei den meisten Standard-PHP-Installationen der Fall).
3. `data/` und `data/presets/` müssen für den Webserver-Prozess beschreibbar
   sein (Cache-Datenbank bzw. Preset-Dateien).
4. Seite im Browser öffnen (bevorzugt Querformat/Landscape).

## Wichtiger Hinweis zum Protokoll

Yamaha veröffentlicht dieses Protokoll nicht vollständig offiziell (und hat
es inzwischen wohl in "RCP" umbenannt – ob 100 % kompatibel zu "SCP" ist
nicht offiziell bestätigt, verhält sich in der Praxis aber bisher identisch).
Die verwendeten Pfade stammen aus inoffiziell zusammengetragener
Dokumentation (`companion-module-yamaha-rcp`, `yamaha-rcp-docs`) sowie aus
gemeinsamem Testen gegen das echte Pult.

**Bestätigt funktionierend:** Fader-Level/On/Name/Color für InCh/StInCh/Mix/
Mtrx, Matrix-Routing (Lesen), Dyna1/Dyna2-Threshold.
**Nach Namensschema angenommen, ungetestet:** HPF, EQ-Freq/Gain/Q/On,
Dyna1-Range/Attack/Hold/Decay, Dyna2-Ratio/Attack/Release/OutGain/Knee.

Zum Verifizieren: **Log**-Button (letzte Aktionen) oder **Debug**-Modal
(freie Konsole) benutzen und den Rohverkehr mit dem tatsächlichen
Pult-Verhalten abgleichen. Falls das Antwortformat abweicht, sitzt die
Auswertung in `includes/YamahaSCP.php::parseResponse()`.

## Externe API (GET)

`api.php` lässt sich auch von außen ansteuern (curl, Browser-URL, Stream
Deck, Home-Automation o.ä.) – rein per GET, kein POST/Body nötig.

**Wert lesen:**
```
GET /api.php?action=get&category=inch&index=0&param=level
GET /api.php?action=get&category=mix&index=3&param=on
GET /api.php?action=get&category=inch&index=0&param=color
```
Antwort z.B. `{"ok":true,"category":"inch","index":0,"param":"level","value":-600,"value_db":-6.0}`

**Wert setzen:**
```
GET /api.php?action=set&category=inch&index=0&param=level&value=-600
GET /api.php?action=set&category=inch&index=0&param=on&value=1
GET /api.php?action=set&category=inch&index=0&param=color&value=Blue
```

**Gültige Werte für `category`:** `inch`, `stinch`, `mix`, `mtrx`
**Gültige Werte für `param`:** `level` (Rohwert = dB×100, -32768 = -∞),
`on` (0/1), `name` (String), `color` (String, z.B. `Blue`/`Red`/`Off`)

**Discovery** (Kanalanzahl + Werteformate abfragen, für automatisierte Clients):
```
GET /api.php?action=config
```

Weitere GET-fähige Endpunkte (identisch nutzbar, siehe jeweilige Abschnitte
oben bzw. direkt im Code): `status`, `routing`, `eq_get`/`eq_set`,
`dyn_get`/`dyn_set`, `raw_cmd`, sowie:
```
GET /api.php?action=preset_list
GET /api.php?action=preset_save&slot=1&name=Soundcheck
GET /api.php?action=preset_load&slot=1
```
`preset_save`/`preset_load` können wegen der über 1000 Einzelbefehle pro
Snapshot mehrere Sekunden dauern (Timeout beim Aufrufer entsprechend großzügig
wählen) und lösen **anders als in der Weboberfläche keine Bestätigungsabfrage**
aus – der Bestätigungsdialog ist reine Frontend-Logik, ein direkter API-Aufruf
überschreibt Preset bzw. Live-Zustand sofort.

**Kein Auth-Schutz.** Wie der Rest der App geht diese API von einem
vertrauenswürdigen lokalen Netzwerk aus – nicht ohne Weiteres ins Internet
exponieren.

## Änderungen

- **1.1.0** – Externe GET-API (`action=get`/`set`/`config`), `set` unterstützt
  jetzt auch `name`/`color`, Info-Fenster mit API-Beispielen im Debug-Modal
- **1.0.0** – Faderansicht/-steuerung, EQ- und Dynamics-Modal, Matrix-Routing
  (lesend), Presets, Demo-Modus, Befehlskonsole, Log, Kanalfarben, responsives
  Layout

## Bekannte Lücken / Ideen für später

- Matrix-Routing ist nur lesend – Zellen anklickbar machen, um Sends zu
  routen/pegeln
- EQ-Filtertyp (Shelf vs. Bell umschaltbar) für Band 1/4 nicht editierbar
  (Integer-Werte der Typ-Enumeration nicht bestätigt)
- Live-Pegelanzeige (Meter) statt reiner Fader-Position
- NOTIFY-Nachrichten dauerhaft mitlesen (persistente Verbindung statt
  Poll-Intervall) für Echtzeit-Feedback bei Bedienung direkt am Pult
