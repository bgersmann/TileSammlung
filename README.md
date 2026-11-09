# TileSammlung

Sammlung von HTML-SDK-Kacheln für die Kachel-Visualisierung (Design angelehnt an UnifiNet).

## Module

### HausgeraeteKachel

Zeigt **ein** Haushaltsgerät (Waschmaschine, Trockner, Spülmaschine, Ofen, ...) als Kachel –
pro Gerät eine Instanz:

- **Läuft:** Zustand und voraussichtliches Ende in der Kopfzeile, Fortschrittsbalken mit
  Restzeit, Leistung und Verbrauch des Laufs, darunter Gesamtenergie und letzte Laufzeit.
- **Aus:** aktuelle Leistung in der Kopfzeile, letztes Ende, letzte Laufzeit, Verbrauch des
  letzten Laufs, Gesamtenergie.

Alle Variablen sind optional und werden in der Instanzkonfiguration zugeordnet – nicht
konfigurierte Werte werden nicht angezeigt, dadurch passt die Kachel für ganz
unterschiedliche Geräte. Name (leer = Instanzname) und Icon sind frei wählbar.

Ein Gerät gilt als aktiv, wenn die Leistung über der Schwelle liegt (Standard 10 W) oder der
Fortschritt zwischen 1 und 99 % steht.

Restzeit: Variablen ohne Profil werden als Sekunden interpretiert und als Dauer formatiert;
Variablen mit Profil (z. B. "87 min") werden unverändert angezeigt.

Icons: beliebige Font-Awesome-Namen (light), z. B. `washing-machine`, `dryer-heat`,
`dishwasher`, `oven`.

Die Kachel passt sich der Größe an: bei kleinen Kacheln werden Detailzeilen automatisch
ausgeblendet (2x2 bis 6x4 getestet).

## Eigene Kacheln ergänzen

Neues Modulverzeichnis neben `HausgeraeteKachel` anlegen, `libs/TileHelper.php` einbinden
(`use TileHelper;`), `TileState()` und `TileFile()` implementieren und eine eigene
`tile.html` nach dem gleichen Muster erstellen.
