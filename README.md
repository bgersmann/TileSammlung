# TileSammlung

Sammlung von HTML-SDK-Kacheln für die Kachel-Visualisierung (Design angelehnt an UnifiNet).

## Module

### HausgeraeteKachel

Zeigt **ein** Haushaltsgerät (Waschmaschine, Trockner, Spülmaschine, Ofen, ...) als Kachel –
pro Gerät eine Instanz:

- **Läuft:** Zustand und voraussichtliches Ende in der Kopfzeile, Fortschrittsbalken mit
  Restzeit, Leistung und Verbrauch des Laufs, darunter die letzte Laufzeit.
- **Aus:** aktuelle Leistung in der Kopfzeile, letztes Ende, letzte Laufzeit, Verbrauch des
  letzten Laufs.

Alle Variablen sind optional und werden in der Instanzkonfiguration zugeordnet – nicht
konfigurierte Werte werden nicht angezeigt, dadurch passt die Kachel für ganz
unterschiedliche Geräte. Name (leer = Instanzname) und Icon sind frei wählbar.

Aktiv-Erkennung: Maßgeblich ist der **Rohwert der Zustandsvariable** – er zählt als „läuft",
wenn er in der Liste „Zustandswerte, die als 'läuft' gelten" steht (kommagetrennt, z. B.
`1, 2` für ein Profil mit 0=Aus/1=An/2=Läuft/3=Fertig, oder Texte bei String-Variablen).
Nur wenn keine Zustandsvariable oder keine Liste konfiguriert ist, gilt ein Fortschritt
zwischen 1 und 99 % als „läuft". Die Leistung wird nur angezeigt, nicht ausgewertet.

Restzeit: Variablen ohne Profil werden als Sekunden interpretiert und als Dauer formatiert;
Variablen mit Profil (z. B. "87 min") werden unverändert angezeigt.

Icons: beliebige Font-Awesome-Namen (light), z. B. `washing-machine`, `dryer-heat`,
`dishwasher`, `oven`.

Die Kachel passt sich der Größe an: bei kleinen Kacheln werden Detailzeilen automatisch
ausgeblendet (2x2 bis 6x4 getestet).

### IconRaster

Zeigt beliebige Variablen und Skripte als **Raster** (oben links nach unten rechts, Reihenfolge
per Drag & Drop in der Liste). Pro Eintrag ist wählbar, ob **Icon, Wert und/oder Name**
angezeigt werden; das Icon kommt aus der Liste, sonst vom Objekt, sonst ein Standard.

- **Bool-Variablen mit Aktion** werden per Tipp umgeschaltet (RequestAction) und im aktiven
  Zustand farblich hervorgehoben; Bool-Variablen ohne Aktion sind reine Anzeige.
- **Skripte** werden per Tipp gestartet (kurzes Aufleuchten als Rückmeldung).
- Alle anderen Variablen sind reine Anzeige.
- **Gruppen:** Aufeinanderfolgende Einträge mit gleichem Gruppennamen erhalten eine
  hervorgehobene Gruppenüberschrift (Akzentmarker + Trennlinie) über die volle Breite.

Das Raster passt Zellgröße und Schrift automatisch an die Kachelgröße an.

### WetterKachel

Wetterübersicht in einer Kachel: große Temperatur mit „Gefühlt …" darunter, UV-Index als
farbiger Chip (grün/gelb/orange/rot/violett nach Index), Wind mit gedrehtem Richtungspfeil,
Himmelsrichtung, Geschwindigkeit und optionaler Böe, Kennzahlen für Luftfeuchte, Taupunkt
und Luftdruck sowie der Regen von heute mit einem kleinen Stundengraphen.

Alle Variablen sind optional. Der Regen-Graph liest die Stundenmengen aus dem Archiv:
als Quelle eine **geloggte, kumulierte Regenmenge** (z. B. „Regenmenge Tag") angeben;
pro Stunde wird Max−Min gebildet (bei Zähler-Aggregation direkt die Menge), gecacht für
5 Minuten. Die Windrichtung erwartet Grad (0–360), der Pfeil zeigt die Strömungsrichtung.

### AutoKachel

Fahrzeugübersicht – eine Instanz pro Auto: Tank-/Akkustand als Balken (gelb ≤ 20 %,
rot ≤ 10 %), Reichweite und Kilometerstand als Kennzahlen, Verriegelung (Status-Punkt:
grün = verriegelt, gelb = offen; Vergleichstext einstellbar), Türen/Fenster/Kofferraum
als Zeilen, unten Parkadresse und letzte Aktualisierung. Alle Variablen optional –
passt damit auch für Autos mit wenigen Datenpunkten. Klick auf einen Wert öffnet die
Variable.

### HundKachel

Haustier-Tracker (z. B. Tractive) – eine Instanz pro Tier: Status und „Unterwegs" in
der Kopfzeile (pulsierender gelber Punkt bei unterwegs), Akku als Balken, Signalton /
Signallicht / Live-Tracking als Schalter (nur Bool-Variablen mit Aktion; aktiv =
Akzentfarbe), Geschwindigkeit und Positionsgenauigkeit als Kennzahlen, Zeilen für
letzte Positionsmeldung, Schlafzeiten, Sensor und Höhe. Klick auf einen Wert öffnet
die Variable.

## Eigene Kacheln ergänzen

Neues Modulverzeichnis neben `HausgeraeteKachel` anlegen, `libs/TileHelper.php` einbinden
(`use TileHelper;`), `TileState()` und `TileFile()` implementieren und eine eigene
`tile.html` nach dem gleichen Muster erstellen.
