# Changelog

## 0.33.0-beta.1 (2026-07-18)

- **Neu: Energie-Ausgabe wahlweise in Wh statt kWh.** Schalter „Energie in Wh statt kWh
  ausgeben" (Datenpunkte-Panel). Aktiviert, werden alle Energiewerte (Ertrag, Bezug,
  Einspeisung, Ladung/Entladung, Hausverbrauch usw.) in der Basiseinheit Wh geführt –
  konsistent zur Leistung (W); die neue IP-Symcon-Darstellung skaliert dann selbst auf
  Wh/kWh/MWh und nutzt das lokale Dezimaltrennzeichen. Standard bleibt kWh, damit bestehende
  Instanzen keinen Sprung in der Historie bekommen. Die Umstellung erfolgt zentral (Wh-Profil
  „IHB.Wh" + Wert ×1000), unabhängig vom Hersteller.

## 0.32.4-beta.1 (2026-07-18)

- **Klarere Benennung Hauslast (Eingang vs. Ausgang).** Das Feld in der Wechselrichter-Instanz
  heißt jetzt „Externer Hauslastzähler — Eingang (optional)" und weist explizit darauf hin,
  dass es ein *Eingang* für einen gemessenen Wert ist (echter Verbrauchszähler, immer positiv)
  — im Unterschied zur in der Kachel optional *ausgegebenen* Variable „Hauslast (berechnet)".
  Ein Querverweis nennt direkt, wo der berechnete Wert ausgegeben wird.

## 0.32.3-beta.1 (2026-07-18)

- **Dokumentation & In-Modul-Hilfe auf den aktuellen Stand gebracht.** Die Hilfe im
  Instanz-Formular listet jetzt alle 13 Hersteller inkl. Anschluss-Besonderheiten (Kostal
  Port 1502/Byte-Reihenfolge, Victron Unit-ID 100, Huawei Unit-ID 1, Fronius Smart-Meter-
  Adresse, SolaX-Gateway), die modulweite Vorzeichen-Konvention samt Invers-Schaltern und die
  Riso-Verfügbarkeit. README: Treiberliste vollständig, SolarEdge-/Fronius-Umfang aktualisiert,
  Hinweise zu Invers-Schaltern (Kachel-Rückrechnung), Hauslastzähler und der neuen Variable
  „Hauslast (berechnet)" ergänzt.

## 0.32.2-beta.1 (2026-07-18)

- **Kachel: berechnete Hauslast optional als Variable.** Neuer Schalter „Berechnete Hauslast
  zusätzlich in eine Variable schreiben" (im Panel „Datenquelle"). Ist er aktiv, legt die
  Kachel die Variable „Hauslast (berechnet)" (W) an und aktualisiert sie bei jeder
  Datenänderung — nutzbar für Automationen, Charts usw.

## 0.32.1-beta.1 (2026-07-18)

- **Kachel: Schalter „Netz-Leistung invertieren" wird jetzt berücksichtigt.** Wer die
  Meter-Leistung im Wechselrichter-Modul umdreht (z. B. für die Konvention Einspeisung =
  negativ), bekam bisher in der Kachel eine falsche Netz-Flussrichtung und eine überhöhte
  Hauslast. Die Kachel rechnet den Invers-Schalter nun intern wieder auf ihre kanonische
  Konvention (+ = Einspeisung) zurück — analog zum bereits vorhandenen Batterie-Invers-
  Schalter. Datenpunkt-Vorzeichen und Kachel stimmen damit unabhängig voneinander.

## 0.32.0-beta.1 (2026-07-18)

Nach Fronius-Beta-Wünschen (zusätzliche Messwerte):

- **Fronius: PV-Strom je String** (MPPT1/MPPT2 Strom aus Model 160 DCA) in der Gruppe
  „PV-Details".
- **Fronius: Batteriespannung** (aus dem DCV der Speichermodule in Model 160) in der Gruppe
  „Batterie".
- **Fronius: Smart Meter je Phase** — neue Gruppe „Smart Meter je Phase" mit Spannung, Strom
  und Leistung pro Phase (L1/L2/L3), gelesen aus dem SunSpec-Meter-Model (Int-20x mit
  Skalierungsfaktor oder Float-21x). Leistungs-Vorzeichen wie beim Gesamtwert
  (positiv = Einspeisung).

## 0.31.4-beta.1 (2026-07-18)

- **Kachel: negativen Hauslastzähler abfangen.** Wird als „Hauslastzähler" versehentlich eine
  Variable gewählt, die negativ werden kann (z. B. ein Netz-/Einspeisezähler statt eines
  echten Hausverbrauchszählers), zeigte die Kachel eine negative Hauslast und daraus absurde
  „Wandlungsverluste". Ein Hausverbrauch ist nie negativ — solche Werte werden jetzt ignoriert
  und die Kachel bleibt bei der berechneten Bilanz-Hauslast (keine Verluste-Anzeige).

## 0.31.3-beta.1 (2026-07-18)

Nach Victron-Beta-Test:

- **Victron: PV und Batterie kamen nicht an** (nur Netz/Verbrauch). Ursache: Der Batterie-/
  DC-PV-Block wurde in einem Rutsch von Reg 840 bis 850 gelesen — dieser Bereich überspannt
  die reservierten Register 845–849, was manche GX-Firmwares mit einer Modbus-Exception
  quittieren und damit den gesamten Block scheitern lassen. Jetzt werden die DC-PV-Leistung
  (Reg 850) und der Batterieblock (840–844) getrennt gelesen; PV-Gesamtleistung, SOC,
  Batterieleistung/-spannung/-strom und -zustand kommen damit an.

## 0.31.2-beta.1 (2026-07-18)

Nach SolarEdge-Kachel-Rückmeldung:

- **SolarEdge: Batterie-Vorzeichen auf Modul-Konvention korrigiert.** SolarEdge meldet
  Batterie-Leistung/-Strom mit + = Laden (0xE174/0xE172); das Modul nutzt aber durchgängig
  + = Entladen / − = Laden. Dadurch zeigte die Kachel die Lade-/Entlade-Pfeile verkehrt
  herum. Leistung und Strom werden jetzt negiert → korrekte Flussrichtung. (Wer die
  Datenpunkt-Werte lieber in der anderen Konvention möchte, nutzt den Schalter
  „Batterie-Leistung invertieren" — die Kachel bleibt dank interner Rückrechnung korrekt.)
  Die berechnete PV-Erzeugung wurde entsprechend angepasst (PV-Gesamt + Batterie-
  Ladeleistung), sodass ihr Ergebnis unverändert bleibt.
- **Kachel: berechnete PV-Erzeugung (`pv_real`) hat Vorrang vor `pv_total`.** Ist die
  optionale PV-Erzeugung aktiv (z. B. SolarEdge StorEdge), zeigt die Kachel diesen Wert statt
  der DC-Leistung, die bei Batteriebetrieb nicht die reine PV abbildet.

## 0.31.1-beta.1 (2026-07-18)

- **Fronius: Batterie-SOC jetzt mit einer Nachkommastelle** (Float statt Integer). Fronius
  liefert den SOC ohnehin als Float (Model 124 ChaState) — so sieht man schneller, in welche
  Richtung die Ladung läuft. Bestehende Integer-SOC-Variablen werden beim Übernehmen
  automatisch auf Float migriert (neue allgemeine Typ-Migration in `RegisterVar`: ändert sich
  der gewünschte Variablentyp, wird die Variable neu angelegt, da IPS den Typ nicht
  nachträglich ändern kann).

## 0.31.0-beta.1 (2026-07-18)

- **Isolationswiderstand (Riso) für Sungrow, SMA und Kostal ergänzt.** Damit lesen jetzt
  GoodWe, Huawei, Sungrow, SMA und Kostal den für die PV-Sicherheit wichtigen Riso-Wert.
  Register je Hersteller gegen gepflegte Fremdquellen verifiziert:
  - Sungrow: Input-Register 5071 (U16, kΩ).
  - SMA: Holding 30225 aus dem proprietären SMA-Profil (uint32 in Ohm → kΩ; Sentinel
    0xFFFFFFFF „nicht verfügbar" wird übersprungen).
  - Kostal: Holding 120 (Float32 in Ohm → kΩ, mit der eingestellten Byte-Reihenfolge).
  - Growatt: optionale Gruppe „Isolationswiderstand (modellabhängig)" (Input-Register 200,
    kΩ). Laut Quelle nur bei manchen Modellen belegt (z. B. MIC 600TL-X bestätigt), daher
    opt-in und klar gekennzeichnet — bitte am eigenen Modell auf Plausibilität prüfen.
  - Für Solis, SolaX, Deye und Solplanet ist über die geprüften Modbus-Quellen kein
    zuverlässiger Riso-**Messwert** dokumentiert (nur Fehlercodes/Schwellwerte);
    Fronius/SolarEdge liefern ihn im reinen SunSpec-Modell nicht. Dort wird Riso bewusst
    nicht geraten.

## 0.30.2-beta.1 (2026-07-18)

- **Huawei: Isolationswiderstand (Riso) ergänzt** (Reg 32088, MΩ). Damit lesen jetzt GoodWe
  und Huawei den für die PV-Sicherheit wichtigen Riso-Wert aus.

## 0.30.1-beta.1 (2026-07-18)

- **Discovery erkennt jetzt auch Victron GX und Huawei SUN2000.** Damit deckt der
  Netzwerk-Scan alle unterstützten Hersteller ab. Victron wird über den Systemdienst auf
  Unit-ID 100 (Serial-Register) erkannt, Huawei über Modell-ID/Modellname (Reg 30070/30000)
  auf Unit-ID 1/0/16.

## 0.30.0-beta.1 (2026-07-18)

- **Neuer Hersteller: Huawei SUN2000 (L1/M1)** inkl. DTSU666-Zähler und LUNA2000-Batterie.
  Native Huawei-Registermap (kein SunSpec, FC 0x03, Big-Endian, int16/int32 mit Gain);
  Register/Gain verbatim aus der Bibliothek `wlcrs/huawei-solar-lib`. Ausgelesen werden:
  PV-Eingangsleistung (32064), AC-Wirkleistung (32080), Netz U/I/f (32069/32072/32085),
  Innentemperatur (32087), Betriebsstatus (32089), Ertrag Gesamt/Heute (32106/32114),
  Smart Meter DTSU666 (Leistung 37113, U/I/f), Batterie LUNA2000 (SOC 37004, Leistung 37001,
  Spannung 37003, Strom 37021, Temperatur 37022, Zustand 37000) und der Modellname (30000).
  Unit-ID des Wechselrichters meist 1 (je nach Konfiguration auch 0/16), Port 502. Vorzeichen
  von Netz (+ Einspeisung) und Batterie (+ Entladen) auf Modul-Konvention gebracht. Noch nicht
  am realen Gerät verifiziert.

## 0.29.1-beta.1 (2026-07-18)

Nach weiterem SolarEdge-Beta-Test:

- **SolarEdge: Zähler-Energie neu** — optionale Gruppe „Zähler-Energie (Bezug / Einspeisung)"
  liest die kWh-Zählerstände aus dem SunSpec-Meter-Modell (TotWhImp/TotWhExp mit gemeinsamem
  Skalierungsfaktor).
- **SolarEdge: Batterie SOH + Speicherstatus neu** — die Batteriegruppe liefert jetzt auch
  State of Health (0xF582) und den Speicherstatus (0xE186: Aus/Laden/Entladen/Ruhemodus).
  Alle Batterie-Register gegen die vom Tester exportierte StorEdge-Vorlage gegengeprüft.
- **SolarEdge: PV-Erzeugung berechnet neu** — optionale Gruppe „PV-Erzeugung berechnet". Auf
  StorEdge-Anlagen spiegelt das DC-Leistungsregister bei Batteriebetrieb nicht die reine PV-
  Erzeugung wider; die neue Variable rechnet nach der am Gerät bewährten Formel PV-Gesamt +
  Batterieleistung (nie negativ). Benötigt die aktive Batteriegruppe.

## 0.29.0-beta.1 (2026-07-18)

- **Neuer Hersteller: Victron GX (Cerbo / Venus OS)**. Liest den aggregierten Systemdienst
  `com.victronenergy.system` per Modbus TCP: PV-Gesamtleistung (DC- + AC-gekoppelt),
  Netzleistung, Batterie (SOC, Leistung, Spannung, Strom, Zustand), Hausverbrauch und aktive
  Netz-Quelle. Register verbatim aus Victrons offizieller `attributes.csv`. Besonderheit: Bei
  Victron ist die Unit-ID ein Geräte-Selektor — der Systemdienst liegt fest auf 100, das
  Modul spricht diese automatisch an (Port 502). Vorzeichen von Netz (+ Einspeisung) und
  Batterie (+ Entladen) auf Modul-Konvention gebracht. Noch nicht am realen Gerät verifiziert.

## 0.28.2-beta.1 (2026-07-18)

Nach SolarEdge-Beta-Test (SE10K-RWS48BEN4):

- **SolarEdge: Skalierungsfaktoren im Integer-Modell (Model 103) korrigiert**. Ursache der
  „falschen Kommastellen": SolarEdge überträgt Spannung/Strom/Temperatur als int16 mit
  separatem SunSpec-Skalierungsfaktor-Register (10^SF). Diese wurden für Spannung und Strom
  gar nicht und für die Temperatur mit falschem Divisor angewendet → Werte um Faktor 10/100
  daneben (225 V erschien als 2250 V, 28,9 A als 289 A, 37 °C als 372 °C). Jetzt wird je
  Messwert das zugehörige SF-Register gelesen und angewendet (Spannung, Strom, Frequenz,
  Leistung, DC-Leistung, Temperatur, Gesamtertrag sowie Zähler-Leistung).
- **SolarEdge: Gesamtertrag** wird jetzt auch im Integer-Modell gefüllt (stand vorher auf
  „Nie").
- **SolarEdge: Batterie (StorEdge) neu**. Optionale Gruppe „Batterie (StorEdge)" liest den
  SolarEdge-Batterie-1-Block ab 0xE100: SOC, Leistung, Spannung, Strom, Temperatur. Die
  Float32-Werte dieses Blocks sind – anders als der SunSpec-Inverter-Block (ABCD) – little-
  endian (CDAB); das Modul schaltet dafür gezielt um. Byte-Reihenfolge und Vorzeichen am
  realen Gerät (SE10K + Batterie) verifiziert: Die Batterieleistung folgt bereits der
  Modul-Konvention (+ Entladen / − Laden), keine Invertierung nötig; bei Bedarf per Schalter
  „Batterie-Leistung invertieren" umkehrbar.

## 0.28.1-beta.1 (2026-07-18)

Nach Kostal-Plenticore-Beta-Test:

- **Kostal: Byte-/Wortreihenfolge (CDAB/ABCD) wählbar**. Ursache der unbrauchbaren Werte
  (riesige/negative Zahlen, z. B. Spannung 1,4·10¹³ V): Der Plenticore ist ab Werk auf
  „little-endian (CDAB) Standard Modbus" gestellt, das Modul decodierte Float32 aber fest
  big-endian (ABCD/SunSpec). Neu: Auswahl „Byte-Reihenfolge" (nur bei Kostal), Vorgabe CDAB
  passend zur Werkseinstellung. Muss mit der Einstellung im Plenticore-Webinterface
  (Einstellungen → Modbus/Sunspec) übereinstimmen.
- **Robustheit gegen NaN/INF**: Liefert ein Gerät an einer Register-Adresse keinen
  gültigen Float32 (andere Byte-/Wort-Reihenfolge, kein Float an dieser Adresse), lehnte
  IP-Symcon das Setzen mit wiederholter Warnung ab („NaN/INF Werte werden nicht
  unterstützt"). Solche Werte werden jetzt zentral in `SetVarFloat` auf 0.0 gefiltert –
  keine Log-Flut mehr. (Die eigentliche Kostal-Decodierung wird nach Auswertung der
  Roh-Register des Testers separat korrigiert.)

## 0.28.0-beta.1 (2026-07-17)

Nach Fronius-Beta-Test (Symo GEN24, Batterie + Smart Meter):

- **Kritischer Bugfix Datenpunkte beim Herstellerwechsel**: Eine neu angelegte Instanz zeigte
  immer die GoodWe-Ordner (der Default-Hersteller), auch wenn vor dem Übernehmen „Fronius"
  gewählt war — man musste die Ordner erst löschen und neu wählen. Beim Registrieren werden
  jetzt alle Variablen/Kategorien entfernt, die nicht zum aktuell gewählten Treiber gehören
  (Reste eines anderen Herstellers oder deaktivierter Gruppen).
- **Fronius: Smart-Meter-Adresse konfigurierbar**. Der Fronius Smart Meter ist ein eigenes
  Modbus-Gerät auf derselben IP mit eigener Unit-ID (Vorgabe 200, je nach Konfiguration z. B.
  240). Bisher fest 200 verdrahtet, dadurch fand das Modul den Zähler bei abweichender Adresse
  nicht → keine Netzleistung. Jetzt Feld „Smart-Meter-Adresse" (nur bei Fronius). Die
  Register-Offsets für die Gesamtwirkleistung waren bereits korrekt (Direktwert, nicht
  Spannung×Strom).
- **Batterie-Ordner heißt jetzt „Batterie"** statt „bat" (fehlendes Kategorie-Label bei
  Fronius ergänzt).
- **Neu: Schalter „Batterie-Leistung invertieren"**. Der bisherige Invers-Schalter galt nur
  fürs Smart Meter. Modul-Standard bleibt + = Entladen / − = Laden; wer die umgekehrte
  Konvention möchte, aktiviert den Schalter. Die Kachel rechnet intern automatisch auf ihre
  kanonische Konvention zurück, sodass die Flussrichtung der Batterie korrekt bleibt.

## 0.27.1-beta.1 (2026-07-17)

- **Bugfix Einheit bei Verbrauchern**: Fremdquellen (z. B. Wallboxen) liefern ihre Leistung
  teils in kW statt W, wodurch der Kreis den Wert um Faktor 1000 falsch anzeigte. Die Kachel
  rechnet jetzt jede Verbraucher-Leistung einheitlich in Watt um. Neue Spalte „Einheit" je
  Verbraucher-Zeile: „Automatisch" (Vorgabe) erkennt W/kW/MW am Profil-Suffix der Variable,
  bei fehlendem/unpassendem Profil lässt sich W/kW/MW manuell wählen. Der optionale
  Hauslastzähler wird ebenso automatisch anhand seines Profils umgerechnet.

## 0.27.0-beta.1 (2026-07-17)

- **Bugfix Hauslast**: Die Bilanz nutzt jetzt die AC-Wirkleistung des Wechselrichters
  (`Hauslast = AC-Leistung − Netzeinspeisung`) statt der PV-Leistung. Die AC-Leistung ist
  bereits das, was der Wechselrichter NACH der Batterie ans Hausnetz abgibt — dadurch stimmt
  die Last auch beim Laden (PV 8 kW, Ladung 7 kW → Hauslast 1 kW statt fälschlich 8 kW) und
  braucht keine Batteriedaten mehr. Die frühere PV-basierte Formel überschätzte die Last um die
  Ladeleistung, sobald Batteriedaten fehlten oder die Batterie-Gruppe aus war. Fällt keine
  AC-Leistung vor, greift weiterhin die DC-Bilanz (PV + Batterie-Entladung − Einspeisung).
  Gegen fünf Szenarien verifiziert, u. a. die frühere Live-Messung (1824 W).
- **Knoten-Blitze jetzt sichtbar und leistungsabhängig**: Die Teslaspulen-Blitze rund um die
  Kreise werden mit der Leistung heller (waren vorher kaum zu sehen). Zusätzlich wachsen
  „Reichweiten"-Blitze von der Kreiskante Richtung Nachbarknoten — ihre Länge folgt der
  Leistung und erreicht bei 25 kW den Nachbarn. Bei geringer Leistung nur ein kurzes Zucken an
  der Kante.

## 0.26.0-beta.1 (2026-07-17)

- **Teslaspulen-Blitze jetzt auch um die Kreise**: aktive Knoten (inkl. Hauslast) bekommen
  wabernde Zickzack-Bögen entlang der Münzkante, in der jeweiligen Knotenfarbe und mit
  demselben Flacker-Rhythmus wie an den Leitungen. Jeder Knoten hat ein eigenes, stabiles
  Muster (Position/Timing aus dem Knotenwinkel abgeleitet); bei inaktiven Knoten sind die
  Blitze aus.
- **Fluss-Tempo einstellbar**: neuer Regler „Fluss-Tempo: Leistung für Höchsttempo"
  (Vorgabe 10000 W statt bisher fest 40 kW). Bei diesem Wert laufen die Dreiecke mit
  Höchsttempo — je kleiner, desto deutlicher unterscheiden sich Alltagsleistungen sichtbar.
  Tempo-Spanne zugleich von 2,2-0,5 s auf 3,0-0,4 s je Umlauf gespreizt. Beispiel mit der
  Vorgabe: 8,3 kW → 0,63 s, 1,8 kW → 1,9 s, 0,22 kW → 2,6 s.

## 0.25.0-beta.1 (2026-07-17)

- **Neu: Invers-Schalter für die Meter-Leistung** (alle Hersteller, im Datenpunkte-Panel):
  „Netz-Leistung (Meter) invertieren". Die Zählrichtung hängt vom Einbauort und der
  Verdrahtung des Zählers ab und ist daher je Anlage verschieden — statt je Hersteller zu
  raten, legt der Nutzer die Richtung selbst fest. Wirkt zentral auf `meter_total` und damit
  automatisch auch auf Netz-Farbe/Flussrichtung in der Kachel.

## 0.24.0-beta.1 (2026-07-17)

Fronius-Überarbeitung nach Beta-Test an einem Symo GEN24 12.0 Plus SC:

- **Bugfix MPPT-Werte** ("2056,4 V"): die Model-160-Offsets lasen mitten im
  IDStr-**Textfeld** der Modulblöcke statt bei DCV/DCW - die Modulblöcke beginnen erst nach
  ID + 8 Registern Text. Jetzt korrektes offizielles Layout inklusive Auswertung der
  SunSpec-Skalierungsfaktoren und der "nicht implementiert"-Kennungen (0xFFFF/0x8000).
- **Smart Meter wird jetzt gefunden**: Fronius meldet den Zähler nicht in der SunSpec-Kette
  des Wechselrichters, sondern unter einer **eigenen Unit-ID** (die "Zähleradresse",
  werksseitig 200). Der Treiber liest den Meter jetzt dort (Rückfall: eigene Kette),
  unterstützt Int- (20x, mit Skalierungsfaktor) und Float-Meter-Modelle (21x, W bei
  Offset 26) und dreht das Vorzeichen auf die Modul-Konvention (positiv = Einspeisung).
- **Neu: Batterie-Gruppe für GEN24-Hybride**: SOC aus dem Basic-Storage-Model 124
  (ChaState inkl. Skalierungsfaktor), Lade-/Entladeleistung aus den Speicherkanälen
  Modul 3 + 4 des Model 160 (positiv = Entladung, konsistent zu den anderen Treibern).

## 0.23.0-beta.1 (2026-07-17)

- **Neu in der Discovery: Ausschlussliste** („IPs ignorieren"). Dort eingetragene Adressen
  werden beim Scan komplett übersprungen — gedacht für RTU/TCP-Konverter und andere
  Modbus-Geräte, die sonst fälschlich als Wechselrichter in der Ergebnisliste erscheinen.
  Solche Geräte lassen sich technisch nicht zuverlässig von echten Wechselrichtern
  unterscheiden, da sie Modbus-Anfragen an den dahinterliegenden Bus weiterleiten und daher
  mit plausiblen Werten antworten. Mehrere IPs Komma-, Semikolon- oder Leerzeichen-getrennt;
  ungültige Einträge werden ignoriert.

## 0.22.2-beta.1 (2026-07-17)

- **Kritischer Bugfix Discovery** (gemeldet vom Sungrow-Beta-Tester: nach dem Update wird der
  Wechselrichter nicht mehr gefunden, nur noch der RTU/TCP-Konverter): Regressionsfehler aus
  0.6.3 - die Fortschrittsanzeige feuerte in **jeder** Runde der Portscan-Schleife vier
  `UpdateFormField`-Aufrufe ab. Diese RPCs fraßen das 2-Sekunden-Zeitfenster des Scans auf,
  sodass kaum noch echte Socket-Abfragen stattfanden: schnell antwortende Geräte (der
  Konverter) wurden noch erwischt, langsamere TCP-Handshakes (der Wechselrichter dahinter)
  fielen aus dem Fenster. Die Fortschrittsanzeige ist jetzt auf max. alle 300 ms gedrosselt
  und ihre Laufzeit wird dem Scan-Budget gutgeschrieben.
- Scan-Fenster von 2 s auf 3 s erhöht - Geräte hinter RTU/TCP-Konvertern/Gateways brauchen für
  den TCP-Handshake teils spürbar länger.

## 0.22.1-beta.1 (2026-07-17)

- **Statusanzeige nach unten links** verlegt, damit sie dem Diagramm nicht mehr in die Quere
  kommt, und in die Optik der Kreise eingepasst: eine Plakette mit derselben plastischen
  Fläche und feiner Kante, darin der pulsierende Punkt (bei Verbindung grün mit Leuchtschein).
  Sie ist an der Ecke der Zeichenfläche verankert (die sich ja dem Seitenverhältnis anpasst)
  und wächst mit der Textbreite mit.
- **Bugfix**: Ohne gültige Datenquelle blieb seit der Umstellung auf persistente Knoten das
  alte Diagramm mit veralteten Werten stehen. Es wird jetzt wieder ausgeblendet, sodass nur
  die Statusplakette sichtbar bleibt.

## 0.22.0-beta.1 (2026-07-17)

- **Auto ist jetzt eine „fahrende Batterie"**: das unveränderte Batteriesymbol (gleiche Größe,
  gleiche Ladestands-Füllung mit Prozentwert) plus Räder, um die Hälfte des Rad-Überstands nach
  oben gerückt, damit es mittig sitzt.
- **Eigene Farben je Verbraucher-Art** statt des bisherigen Grün-Rot-Verlaufs: Wärmepumpe in
  Feuer-Orange, Pool-Wärmepumpe/Sauna/Warmwasser in verwandten Wärmetönen, Klimaanlage und
  Pool-Pumpe in Türkis, Fahrzeuge in Violett (bewusst abgesetzt von der blauen Hausbatterie).
  Zusätzlich je Zeile eine **eigene Farbe einstellbar** (leer = Vorgabe der Art).
- **Energiefluss**: je Speiche ein glimmender Leiter, darauf laufende Dreiecke in
  Flussrichtung, dazu wabernde Blitze im Stil einer Teslaspule. Die Dreiecke richten sich
  automatisch entlang der Bahn aus (die Bahn wird in Flussrichtung erzeugt), sodass die Spitzen
  immer korrekt vorausweisen und sich beim Vorzeichenwechsel mitdrehen - z. B. Netz bei Bezug
  zum Haus, bei Einspeisung nach außen; Batterie beim Laden nach außen, beim Entladen zum Haus.
  Das Tempo folgt der Leistung (2,2 s je Umlauf bei wenig, 0,5 s bei 40 kW), Solar fließt
  konstruktionsbedingt immer zum Haus.

## 0.21.0-beta.1 (2026-07-17)

- **Automatische Zuordnung Fahrzeug ↔ Wallbox, ohne eigenen Datenpunkt.** Der bisherige Ansatz
  (eine Variable, die das angeschlossene Fahrzeug benennt) setzte etwas voraus, das die
  wenigsten Anlagen liefern. Stattdessen wird jetzt die Gleichzeitigkeit ausgewertet: Beim
  Einstecken wechseln Wallbox und Fahrzeug jedes für sich auf „verbunden". Als Zeitpunkt dient
  der von IP-Symcon ohnehin geführte Zeitstempel der letzten **Wertänderung**
  (`VariableChanged`, ändert sich nur bei echtem Wertwechsel). Die Paare werden nach zeitlicher
  Nähe sortiert und eindeutig (1:1) vergeben — bei zwei Autos an zwei Wallboxen landet so jedes
  dort, wo es eingesteckt wurde. Das Zeitfenster ist einstellbar (Vorgabe 300 s, 0 = ohne
  Begrenzung).
- **Frei konfigurierbare „verbunden"-Bedingung** je Wallbox und je Fahrzeug, da jede Quelle das
  anders meldet: Variable + Bedingung (ist gesetzt / gleich / ungleich / größer / kleiner …) +
  Vergleichswert. Damit lassen sich Boolean (z. B. „Ladeportklappe offen"), Text (z. B.
  „Ladekabeltyp", leer = kein Kabel) und Integer (z. B. go-e „Kabel-Leistungsfähigkeit",
  0 = kein Kabel) gleichermaßen auswerten.
- Das an einer Wallbox erkannte Fahrzeug wird als Zusatzzeile im Kreis angezeigt, damit die
  automatische Zuordnung nachvollziehbar bleibt.
- Ersetzt die Spalten „Fahrzeug-Zuordnung" und „Kennung" aus 0.20.0.

## 0.20.0-beta.1 (2026-07-16)

- **Wallboxen zeigen jetzt ein Auto mit Ladestand** statt der Ladesäule: Das Auto-Symbol füllt
  sich – wie das Batteriesymbol – entsprechend dem SOC des Fahrzeugs, das gerade an dieser
  Wallbox steht, mit Prozentwert darin. Ohne angeschlossenes Fahrzeug bleibt nur der Umriss.
- **Neu: Fahrzeug-Tabelle** (Bezeichnung, Kennung, Ladestand-Variable) und zwei zusätzliche
  Spalten je Wallbox-Zeile:
  - *Fahrzeug angeschlossen* – boolesche Variable der Wallbox.
  - *Fahrzeug-Zuordnung* – Variable, deren Wert das angeschlossene Fahrzeug benennt.
- **Zuordnung mehrerer Autos zu mehreren Wallboxen**: Der Wert der Zuordnungs-Variable wird
  gegen die Kennung der Fahrzeuge verglichen (Groß-/Kleinschreibung egal; leere Kennung = die
  Bezeichnung). Bei genau einem Fahrzeug darf die Zuordnung leer bleiben, dann ist die Lage
  eindeutig. Bei mehreren Fahrzeugen ohne Zuordnung wird bewusst **kein** Ladestand angezeigt,
  statt eine Zuordnung zu raten.

## 0.19.0-beta.1 (2026-07-16)

- **Verbraucher jetzt als freie Tabelle** statt fester Felder: je Zeile Art, Bezeichnung und
  Leistungs-Variable. Damit sind beliebig viele Verbraucher jeder Art möglich (auch mehrere
  Wallboxen). Verfügbare Arten: Wallbox, Wärmepumpe, Klimaanlage, Pool-Wärmepumpe, Pool-Pumpe,
  Sauna, Warmwasser, Trockner, Sonstiger Verbraucher — die Art bestimmt das Icon, eine leere
  Bezeichnung fällt auf die Vorgabe der Art zurück. Ersetzt die Felder „Wärmepumpe" und
  „Wallboxen" aus 0.18.0 (dort ggf. neu eintragen).
- **Wallbox-Icon neu**: Ladesäule mit Blitz, Kabel und Stecker statt der bisherigen Box, die
  sich nicht als Wallbox lesen ließ.
- **Bugfix Zuschnitt**: In breiten Kacheln wurden die unteren Kreise abgeschnitten, der obere
  Abstand blieb dabei konstant. Ursache war eine feste viewBox mit unsymmetrischem Leerraum
  (oben 67, unten 45 Einheiten): Der Inhalt saß darin nicht mittig und wurde nach unten
  gedrückt. Die viewBox passt sich jetzt dem tatsächlichen Seitenverhältnis der gerenderten
  Fläche an, wodurch das Zentrum immer exakt mittig liegt und der Inhalt die kürzere Seite
  voll ausnutzt. Zusätzlich hängt das SVG jetzt am Viewport der Kachel statt an der body-Box,
  die bei manchen Hosts höher ausfallen kann als der sichtbare Bereich.

## 0.18.0-beta.1 (2026-07-16)

- **Neu: Wärmepumpe und beliebig viele Wallboxen** als zusätzliche Verbraucher-Kreise in
  `InverterHubTile`. Sie stammen nicht aus dem Wechselrichter, sondern werden im neuen Panel
  „Weitere Verbraucher (optional)" als vorhandene Leistungs-Variablen ausgewählt: Wärmepumpe
  als einzelne Variable, Wallboxen als Liste mit frei wählbarer Bezeichnung (z. B. „Garage",
  „Carport") - also mehrere Wallboxen möglich. Die Kachel abonniert diese Variablen selbst und
  aktualisiert sich bei deren Änderung.
- **Anordnung skaliert jetzt mit der Knotenzahl**: Kreisgröße und -abstand werden aus der
  Anzahl berechnet (Sehnenformel), sodass sich benachbarte Kreise auch bei vielen Verbrauchern
  nie berühren. Bis acht Knoten bleibt es bei der bisherigen Größe, darüber verkleinern sich
  alle Kreise gemeinsam. Der äußere Rand liegt konstruktionsbedingt immer exakt gleich weit
  vom Zentrum - die Kachel behält damit unabhängig von der Knotenzahl ihre Größe, und die
  Statuszeile bleibt bündig. Der bisherige Vier-Knoten-Fall bleibt unverändert
  (oben/rechts/unten/links, gleiche Größe).
- Verbraucher werden zwischen Batterie und Netz eingereiht und folgen farblich derselben
  Semantik wie die Hauslast: grün, solange lokal aus PV/Batterie gedeckt, rot bei Netzbezug.
- Bugfix am Rande: bei Icons wurden `circle`/`path`-Konturen gefüllt statt als Umriss
  gezeichnet — die CSS-Ausnahme für Konturformen deckte nur `rect`/`polygon` ab.

## 0.17.0-beta.1 (2026-07-16)

- **Gleitender Wechsel aktiv/inaktiv**: der Zustandswechsel eines Kreises springt nicht mehr,
  sondern läuft weich ab - Größe, Deckkraft, Farbe (Identitätsfarbe ↔ Grau), Corona und alle
  Münz-Ebenen (Wölbung, Kantenanschliff, Glanzlicht, Glint, Bodenschatten) blenden gemeinsam
  über.
- **Neu konfigurierbar**: „Übergangszeit aktiv/inaktiv" (Standard 800 ms, 0 = ohne Animation).
- Dafür nötiger Umbau: die Knoten werden jetzt **einmalig** aufgebaut und danach nur noch
  aktualisiert, statt bei jedem Update komplett neu erzeugt zu werden — vorher startete jedes
  Element bereits im Zielzustand, weshalb CSS-Transitions grundsätzlich nicht greifen konnten.
  Ein Neuaufbau erfolgt nur noch, wenn sich die Menge der vorhandenen Datenpunkte ändert.
  Position und Skalierung stecken jetzt in einem gemeinsamen, animierbaren `transform`; die
  Knotenfarbe ist als typisierte Custom Property (`@property`) registriert, damit der Browser
  den Farbwechsel überhaupt interpolieren kann.

## 0.16.1-beta.1 (2026-07-16)

- **Anordnung korrigiert**: die äußeren Kreise sind nicht mehr auf feste
  Himmelsrichtungen genagelt (was bei fehlendem Knoten eine unschöne Lücke an einer festen
  Seite hinterließ), sondern werden wieder gleichmäßig radial um die Hauslast verteilt - die
  bevorzugte Reihenfolge/Seite (Solar oben, Batterie rechts, Netz unten, Verluste links) gibt
  dabei nur die Reihenfolge im Uhrzeigersinn vor. Bei allen vier vorhandenen Knoten ergibt sich
  weiterhin exakt oben/rechts/unten/links; fehlt einer, bleibt die Anordnung ausgewogen
  (z. B. gleichseitiges Dreieck bei drei Knoten) statt lückenhaft.

## 0.16.0-beta.1 (2026-07-16)

- **Corona verstärkt und an die Leistung gekoppelt**: klare Kennlinie von 0 (bei 0 W) bis
  Maximum (bei 40 kW), perzeptuell (Wurzel), sodass auch mittlere Leistungen sichtbar sind.
  Am Maximum wächst nicht nur die Deckkraft (bis 0,95), sondern auch Ringbreite (bis 34 px)
  und Weichzeichnung (bis 22 px) - der Halo blüht bei hoher Leistung spürbar auf.

## 0.15.2-beta.1 (2026-07-16)

- **Echte Prägung**: Icons und Leistungswerte wirken jetzt in die Münzfläche gestanzt statt nur
  leicht schattiert. Über die Alphamaske des Glyphen als Höhenkarte erzeugt eine
  Reliefbeleuchtung (`feSpecularLighting`, Licht von oben-links) metallisch glänzende Grate auf
  den erhabenen Kanten, dazu ein weicher Tiefenschatten unten-rechts.
- Beschriftung (Solar/Netz/… und Hauslast) etwas nach unten gerückt.

## 0.15.1-beta.1 (2026-07-16)

- **Münz-/Schlaglicht-Look für aktive Kreise verstärkt**: gewölbte Fläche mit gerichtetem
  Licht von oben-links, metallischer Kantenanschliff (heller Lichtfang oben-links, dunkler
  Schatten unten-rechts), ein knackiger Glint auf der Kante, eine Bodenschattierung (der Kreis
  „schwebt" über der Fläche) sowie in die Fläche geprägte (Relief-)Icons und Leistungswerte.
  Wirkt jetzt wie eine Münze unter Schlaglicht. Inaktive Kreise bleiben bewusst flach und
  dezent wie bisher.

## 0.15.0-beta.1 (2026-07-16)

- **Plastischer Look für aktive Kreise**: statt einer flachen Füllfarbe jetzt ein dezenter
  Verlauf plus weiches Glanzlicht oben links (wirkt wie ein leicht gewölbter Knopf). Inaktive
  Kreise bleiben bewusst flach wie bisher.
- **Pfeile/Lauflinien und der große Kollektor-Ring um die Hauslast sind für den Moment
  ausgeblendet** (Nutzerwunsch) — die komplette Geometrie dahinter bleibt erhalten und lässt
  sich über eine einzelne Konstante (`SHOW_FLOW`) jederzeit wieder einschalten.

## 0.14.3-beta.1 (2026-07-16)

- Icons in allen Kreisen einen Ticken höher positioniert.
- Flammen-Icon im Verluste-Kreis um 20 % vergrößert.

## 0.14.2-beta.1 (2026-07-16)

- Haussymbol im Hauslast-Kreis um 20 % verkleinert.

## 0.14.1-beta.1 (2026-07-16)

- **Bugfix**: bei inaktiven Knoten schrumpfte bisher nur der Ring (Radius-Attribut), Icon,
  Leistungswert und Beschriftung blieben in fester Pixelgröße stehen und wirkten dadurch
  unproportional groß für den kleineren Kreis. Ring/Glow werden jetzt immer in voller Größe
  gezeichnet und die komplette Knoten-Gruppe (Ring, Icon, Wert, Beschriftung) wird stattdessen
  gemeinsam per CSS-Transform skaliert — die sichtbare Größe bleibt gleich, aber wirklich
  alles im Kreis verkleinert sich jetzt gemeinsam.

## 0.14.0-beta.1 (2026-07-16)

- **Einheitliche Kreisgröße für aktive Knoten**: die Batterie hatte bisher einen eigenen,
  größeren Radius als alle anderen — jetzt sind alle aktiven Knoten (inkl. Zentrum) gleich
  groß. Inaktive Knoten (kein nennenswerter Fluss) werden stattdessen bewusst kleiner
  gezeichnet — der Größenunterschied selbst ist jetzt das Signal für „inaktiv", zusätzlich zu
  Farbe und Transparenz.
- Inaktive Knoten sind zusätzlich noch durchsichtiger (Gruppen-Opazität von 0,5 auf 0,32).
- Icons sitzen weiter oben im Kreis, Leistungswert näher an der Kreismitte.
- Platzreserve für Leistungswerte auf „33,333 kW" (breitester realistischer Wert) ausgelegt.
- Die Einheit „kW" hat jetzt dieselbe Farbe wie der Leistungswert und skaliert relativ zu
  dessen Schriftgröße (em-basiert), statt fest in einer eigenen, gedämpften Farbe/Größe zu
  stehen.

## 0.13.2-beta.1 (2026-07-16)

- **Statuspunkt exakt ausgerichtet**: der pulsierende Punkt ("Verbunden") ist jetzt exakt
  linksbündig mit der linken Kante des Verluste-Kreises und exakt obenbündig mit der Oberkante
  des Solar-Kreises, berechnet aus denselben Geometrie-Konstanten wie der Rest des Diagramms —
  vorher stand er nur ungefähr in der Nähe der oberen linken Ecke.

## 0.13.1-beta.1 (2026-07-16)

- **Kritischer Bugfix Kostal** (gemeldet von einem Beta-Tester: "einige Werte sind mehr als
  Billionen Watt"): gegen die offizielle KOSTAL-Modbus-Dokumentation geprüft und zwei
  Einheiten-Bugs gefunden — Register 118 ("Total home consumption") und 320/322/324/326
  (Ertrag Gesamt/Heute/Jahr/Monat) sind laut Hersteller-PDF in **Wh** dokumentiert, der Treiber
  behandelte sie aber als **W** bzw. ließ bei den Ertragswerten die Umrechnung komplett weg —
  bei älteren Anlagen mit hohem kumuliertem Ertrag entstehen daraus absurd große Zahlen.
  `home_total` läuft jetzt korrekt über das Energie-Profil (`~Electricity`, kWh), alle vier
  Ertragswerte werden korrekt durch 1000 geteilt.
- Batterie-Register (200/210/214/216: Strom/SOC/Temperatur/Spannung) wurden gegen dieselbe
  offizielle Dokumentation geprüft und sind korrekt adressiert — falls bei einer Anlage
  trotzdem keine Batteriewerte ankommen, bitte prüfen, ob die Datenpunkt-Gruppe „Batterie"
  in der Instanzkonfiguration aktiviert ist und ob überhaupt ein Batteriesystem am
  Wechselrichter angeschlossen ist.
- Kostal-Standardport zur Erinnerung: 1502, nicht 502 (laut Hersteller-Doku bestätigt) — daher
  der abweichende Port, den ein Beta-Tester manuell eintragen musste.

## 0.13.0-beta.1 (2026-07-16)

- **Bugfix Skalierung bei kleinen Kachelgrößen**: `width/height:100%` per CSS verlässt sich
  darauf, dass der Kachel-Host dem `<body>` zuverlässig seine tatsächliche Pixelgröße
  durchreicht — das war in der echten IP-Symcon-Kachel-Ansicht nicht immer der Fall, wodurch
  `preserveAspectRatio` gegen eine falsche (zu große) Fläche skalierte und bei kleinen
  Widget-Größen Inhalt am Rand abgeschnitten wurde. Die tatsächliche Größe wird jetzt aktiv per
  JavaScript gemessen (inkl. `ResizeObserver`) und explizit als Pixel-Attribute gesetzt.
- **Statuszeile** ("Verbunden" mit Punkt) ist jetzt an der Diagramm-Geometrie verankert (knapp
  oberhalb/links von Solar- und Verluste-Kreis) statt an einem willkürlichen Fixpunkt.
- **Stärker ausgegraut**: inaktive Knoten (kein nennenswerter Fluss) sind jetzt zusätzlich
  gedimmt (Gruppen-Transparenz) und ihr dunkler Kreishintergrund wird komplett entfernt
  (transparent), statt weiterhin als dunkler Kreis mit grauem Rand hervorzustechen.
- **Batterie größer** und der SOC-Wert steht jetzt direkt im (dafür verbreiterten) Batterie-Icon
  geschrieben, statt als separate Textzeile daneben.

## 0.12.0-beta.1 (2026-07-16)

- **Feste Positionen statt Rotationsverteilung**: Solar liegt jetzt immer oben, Netz immer
  unten, Batterie immer rechts, Verluste immer links — unabhängig davon, wie viele der Knoten
  gerade aktiv sind. Künftige Verbraucher (Wallbox, Wärmepumpe) bekommen eigene freie
  Winkelpositionen, statt alle Knoten bei jeder Änderung neu zu verteilen.
- **Bugfix Pfeilspitzen-Position**: `markerUnits` fehlte, wodurch der Standardwert
  `strokeWidth` die Pfeilspitze mit der Linienstärke (3) multiplizierte — aus einer 9px-Spitze
  wurde faktisch eine 27px-Spitze, die über den Kollektor-Ring hinausragte. Zusätzlich lag der
  Referenzpunkt in der Dreiecksmitte statt an der Spitze. Beides korrigiert, Pfeile enden jetzt
  exakt an Ring-/Kreiskante.
- Icons sitzen weiter oben im Kreis, mehr Abstand zu Wert und Beschriftung.
- Batterie-Icon breiter und liegend, stellt den SOC jetzt direkt als Füllstand im Icon dar.
- Knoten ohne nennenswerten Leistungsfluss werden jetzt komplett ausgegraut (Ring, Icon, Wert,
  Pfeile) statt nur die Leucht-Corona abzuschalten und die Akzentfarbe zu behalten.

## 0.11.2-beta.1 (2026-07-16)

- **Bugfix Randbeschneidung**: die `viewBox` war für die tatsächliche Ausdehnung der äußeren
  Kreise (inkl. Leucht-Corona) zu knapp bemessen — bei bestimmten Winkeln/Kachelgrößen wurden
  Kreise am Rand abgeschnitten. Geometrie neu durchgerechnet, jetzt mit durchgängigem
  Sicherheitsabstand.
- **Bugfix schwarze Pfeilspitzen**: beim Umbau auf variable Knotenanzahl war versehentlich nur
  noch ein einziges, geteiltes `<marker>`-Element für alle Pfeile übrig geblieben (SVG-Marker
  erben keine Farbe von der referenzierenden Linie) — dadurch erschienen alle Pfeilspitzen in
  derselben bzw. der Standardfarbe Schwarz. Jeder Knoten bekommt jetzt sein eigenes, bereits
  passend eingefärbtes Marker-Element.
- Schriftgrößen in den Kreisen verkleinert und Kreisradius leicht angepasst, damit auch
  sechsstellige Werte wie „4,654 kW" nicht mehr eng am Rand stehen.
- Kollektor-Ring um die Hauslast kräftiger (dickerer, dichterer Strich) und mittig zwischen
  Zentrums- und Außenkreisen positioniert statt zu nah am Zentrum.

## 0.11.1-beta.1 (2026-07-16)

- **Bugfix (2. Anlauf) `InverterHubTile`**: Konfigurationsformular ließ sich weiterhin nicht
  öffnen ("Nicht unterstützter Typ: ColorPicker"). Auch `ColorPicker` war falsch geraten — der
  tatsächlich korrekte IP-Symcon-Formularelement-Typ heißt `SelectColor` (offiziell
  dokumentiert). Diesmal gegen die offizielle Symcon-Dokumentation verifiziert statt geraten.

## 0.11.0-beta.1 (2026-07-16)

- **Komplettes Redesign `InverterHubTile`** nach Nutzer-Feedback (Layout wirkte nicht streng
  genug geometrisch, Beschriftung mal über/mal unter dem Wert, Verluste sollten grau statt
  farbig sein):
  - **Radiale Anordnung statt Diamant**: die Hauslast sitzt jetzt fest im Zentrum, alle
    anderen Größen (Solar, Netz, Batterie, Verluste) werden als gleichmäßig im Kreis verteilte
    äußere Knoten dargestellt — Winkel = 360° / Anzahl aktiver Knoten, exakt berechnet statt
    handgesetzter Positionen. Ein gepunkteter Kollektor-Ring um die Hauslast als Sammelpunkt,
    wie in Referenz-Apps üblich.
  - **Variable Knotenanzahl**: die Knoten werden zur Laufzeit aus den vorhandenen Datenpunkten
    erzeugt (nicht mehr vier feste Kreise). Vorbereitet für künftige Verbraucher wie Wallbox
    oder Wärmepumpe als weitere Knoten, ohne das Layout manuell anzupassen.
  - **Striktes, einheitliches Template**: jeder Knoten zeigt in exakt derselben Reihenfolge
    Icon → Wert → Beschriftung, ausnahmslos. Der SOC-Wert der Batterie wandert dafür als
    kleine Zusatzangabe über das Icon, statt als zweite, uneinheitliche Wertzeile.
  - **Verluste nur noch grau** (keine Akzentfarbe mehr), fließen immer vom Zentrum weg.
  - **Bugfix Pfeilrichtung**: bei der Umstellung wurde die Zuordnung von Fließrichtung zu
    Pfeil-Pfad zunächst vertauscht (Solar zeigte wieder Richtung Sonne) - vor Veröffentlichung
    bemerkt und korrigiert.

## 0.10.0-beta.1 (2026-07-16)

- **Neu**: optionaler Hauslastzähler. `InverterHub` bekommt ein neues Konfigurationsfeld
  „Hauslastzähler (optional)" — eine bereits vorhandene Variable mit real gemessener Hauslast
  (z. B. ein Shelly am Hausanschluss) kann ausgewählt werden. Live an einer echten Anlage
  gegengeprüft: die reine PV/Netz/Batterie-Bilanzschätzung lag konsistent ~100-120 W über dem
  tatsächlich gemessenen Wert (Wechselrichter-Eigenverbrauch/Leitungsverluste, die in der
  Bilanz nicht erfasst werden).
- **Neu in `InverterHubTile`**: ist ein Hauslastzähler konfiguriert, zeigt die Kachel die
  genauere, echte Last sowie einen zusätzlichen kleinen Kreis „Wandlungsverluste" mit der
  Differenz zur Bilanzschätzung. Ohne konfigurierten Zähler bleibt alles wie bisher (reine
  Bilanzschätzung, kein zusätzlicher Kreis).

## 0.9.1-beta.1 (2026-07-16)

- **Kritischer Bugfix Last-Berechnung**: Die Bilanzformel ging von der falschen
  Vorzeichenkonvention für die Batterieleistung aus (Subtraktion statt Addition). Live an
  einer echten GoodWe-Instanz verifiziert: `bat_total_pwr`/`bat_power` ist bei allen Treibern
  positiv = Entladung, negativ = Ladung. Beispiel (reale Werte): PV 5657 W, Netzbezug 299 W,
  Batterieladung 4132 W ergab bisher fälschlich 10088 W Last statt korrekt 1824 W.
- **Bugfix Flussrichtung Solar**: Der Pfeil auf der Solar-Leitung zeigte immer von der Mitte
  zur Sonne (als würde Strom AN die PV geliefert) statt umgekehrt. PV speist jetzt immer
  Richtung Zentrum ein, wie es physikalisch korrekt ist.
- **Neu**: Corona (Leucht-Halo) um jeden Kreis skaliert jetzt weich mit der aktuellen Leistung
  (Wurzel-Skala) statt nur an/aus zu schalten.
- **Neu**: Die gesamte Kachel (inkl. Statuszeile, vorher HTML außerhalb des SVG) liegt jetzt in
  einem einzigen SVG mit `viewBox` und skaliert dadurch vollständig proportional mit der
  Widget-Größe im Dashboard. Der bisherige manuelle „Schriftgröße"-Faktor ist dadurch
  überflüssig geworden und wurde entfernt.
- Netz-Icon von Steckdose auf Blitz-Symbol geändert, Icons und Ringe leicht vergrößert für
  bessere Lesbarkeit bei kleinen Kachelgrößen.

## 0.9.0-beta.1 (2026-07-16)

- **Überarbeitung `InverterHubTile`** nach Nutzer-Feedback (Layout und Farben wirkten
  unstimmig, Icons/Zahlen teils schwarz und unlesbar):
  - **Layout**: echtes, auf der Spitze stehendes Quadrat (Diamant) statt schiefer Anordnung —
    Solar oben, Netz links, Last rechts, Batterie unten, alle vier gleich weit vom Mittelpunkt,
    verbunden durch ein Kreuz aus Leitungen statt gebogener Äste.
  - **Farben jetzt semantisch fest**: Solar = Sonnengelb, Netz = Grün bei Einspeisung/Rot bei
    Bezug, Batterie = Blau, Last = weicher Grün-Rot-Verlauf je nach Anteil aus Netzbezug vs.
    PV/Batterie (moderater, stufenloser Farbwechsel statt hartem Umschalten).
  - **Bugfix**: Icons und Zahlen erschienen schwarz/unlesbar, weil `currentColor` in den
    SVG-Kindelementen verwendet wurde — das bezieht sich auf die CSS-Eigenschaft `color`, die
    nie gesetzt war, statt auf `fill`/`stroke`. Jetzt werden Farben direkt über `fill`/`stroke`
    vererbt, zusätzlich erschienen Pfeilspitzen schwarz, da `<marker>`-Inhalte keine Farben vom
    referenzierenden Pfad erben — jetzt werden sie passend zur aktiven Flussfarbe eingefärbt.
  - Da die Kreisfarben jetzt fest vergeben sind, entfallen die bisherigen Farb-Einstellungen
    „Akzent/Box/Text/Textfarbe" — nur Hintergrundfarbe, Schriftart und -größe bleiben
    konfigurierbar.

## 0.8.2-beta.1 (2026-07-16)

- **Bugfix `InverterHubTile`**: Konfigurationsformular ließ sich nicht öffnen
  ("Fehler beim Laden der Konfigurationsform — Nicht unterstützter Typ: ColorEditor"). Der
  korrekte IP-Symcon-Formularelement-Typ heißt `ColorPicker`, nicht `ColorEditor`.

## 0.8.1-beta.1 (2026-07-16)

- **Redesign `InverterHubTile`**: die erste Fassung wirkte optisch unfertig (grelle Volltonringe,
  gerade Linien, die mitten durch die Kreise liefen, tote SOC-Ringe). Komplett überarbeitet nach
  Vorbild bekannter Wechselrichter-Apps: dünne Ringe mit weichem Leuchten nur bei aktivem Fluss
  (neutral grau/weiß im Ruhezustand statt Farbe), sanft geschwungene Bus-Leitungen (Solar–Batterie
  senkrecht, Abzweige zu Netz/Last) mit „marschierenden Ameisen“ und Pfeilspitze statt fester
  Diagonalen, größere/klarere Typografie im deutschen Zahlenformat („5,649 kW“), Batterie-Kreis
  mit Ladestatus-Badge und SOC als Hauptwert. Netz/Batterie wechseln je nach Richtung zwischen
  Grün (Einspeisung/Entladung) und Orange (Bezug/Ladung).

## 0.8.0-beta.1 (2026-07-16)

- **Neu**: `InverterHubTile` — animierte Energiefluss-Kachel für InverterHub (Solar, Netz, Last,
  Batterie mit SOC-Füllstand), analog zu bekannten Wechselrichter-Apps. Als „Datenquelle" wird
  eine beliebige InverterHub-Instanz gewählt; die Kachel liest deren Werte per Ident, unabhängig
  vom Hersteller. Da nicht jeder Treiber dieselben Datenpunkte liefert (z. B. Growatt ohne
  Netzmessung, SMA/Fronius/SolarEdge ohne Batterie), wird ein Kreis ausgegraut statt mit
  falschen Werten befüllt, wenn die zugehörige Größe bei der gewählten Quelle fehlt. Farben,
  Schriftart und -größe sind anpassbar (Muster identisch zu `GoodweETTile`).

## 0.7.0-beta.1 (2026-07-16)

- **Neu**: Checkbox „Kommunikation aktiv" in `InverterHub`, direkt über der Hersteller-Auswahl.
  Deaktivieren stoppt Polling (Schnell-/Langsam-Timer) und Steueraktionen sofort, ohne die
  Instanz zu löschen oder die Konfiguration zu verlieren — praktisch z. B. bei Wartungsarbeiten
  am Wechselrichter oder wenn das Gerät vorübergehend vom Netz genommen wird.

## 0.6.5-beta.1 (2026-07-16)

- **Bugfix**: die in 0.6.3 eingeführte Fortschrittsanzeige erzeugte eine Warnung
  ("Instanz #<id> existiert nicht") in der Konsole, wenn das Konfigurationsformular
  während des laufenden Scans geschlossen wurde — der Scan selbst läuft unabhängig vom
  offenen Formular korrekt weiter, `UpdateFormField()` schlägt in diesem Fall aber
  erwartungsgemäß fehl. Diese (harmlose) Warnung wird jetzt unterdrückt.

## 0.6.4-beta.1 (2026-07-16)

- **Bugfix Discovery**: go-e-Wallboxen (Unit-ID 1) wurden fälschlich als Growatt erkannt — die
  Growatt-Prüfung (Status 0/1/3 + plausible Temperatur) war nicht spezifisch genug und traf
  zufällig auch auf go-e-Register zu. Als zusätzliches, hartes Kriterium wird jetzt zusätzlich
  Holding 23-27 (Seriennummer, ASCII) verlangt, analog zur bereits bestehenden Absicherung bei
  GoodWe/Sungrow/Solis/SolaX/Kostal.

## 0.6.3-beta.1 (2026-07-16)

- `InverterHubDiscovery`: Fortschrittsanzeige während der Netzwerksuche. Ein Fortschrittsbalken
  zeigt live den Portscan (geschätzt anhand verstrichenem Zeitbudget) und danach die
  Hersteller-Erkennung je gefundener IP (Prozentwert + Klartext, z. B. „Prüfe Hersteller:
  192.168.2.50 (3 von 7 offenen Ports) …"). Vorher lief die Suche ohne jede Rückmeldung, bis das
  Ergebnis fertig war.

## 0.6.2-beta.1 (2026-07-16)

- **Bugfix Sungrow**: Basisvariable `meter_total` wurde nie beschrieben (blieb dauerhaft auf 0).
  Vom Beta-Tester an seiner eigenen, seit längerem laufenden Sungrow-SH-6.0RT-Konfiguration
  bestätigtes Register (5601-5602, Meter Active Power Gesamt) als zuverlässige Quelle übernommen.
- Zusätzlich per Gegenprobe bestätigt: die aktuelle Sungrow-Adressierung (direkt, ohne Offset)
  ist korrekt — eine andere Community-Vorlage (SH10RT+SBR128) verwendet durchgehend um 1
  niedrigere Adressen für dieselben Werte, was im Umkehrschluss die hier verwendete,
  tester-bestätigte Nummerierung bekräftigt.
- Neu: `power_flow_status` (Register 13001, vom Tester bestätigt, bisher gelesen aber verworfen).

## 0.6.1-beta.1 (2026-07-16)

- `InverterHubDiscovery` erkennt jetzt auch SolarEdge, Deye, Solplanet und Kostal. Fronius und
  SolarEdge nutzen denselben SunSpec-"SunS"-Marker und werden zusätzlich über den
  Herstellernamen im Common Block unterschieden, statt sich allein auf den Marker zu verlassen.

## 0.6.0-beta.1 (2026-07-16)

Vier weitere Hersteller-Treiber, Register aus community-getesteten Modbus-Vorlagen des
[IP-Symcon-Forums](https://community.symcon.de/c/symcon/vorlagen-modbus/86) übernommen:

- **SolarEdge**: reines SunSpec, nutzt dieselbe Laufzeit-Discovery und dieselben (gegen OpenEMS
  verifizierten) Feldoffsets wie Fronius/SMA — die Vorlage bestätigt diese Offsets zusätzlich
  unabhängig
- **Deye** (SG04LP3-Serie): direkte Adressierung, Einzelregister. PV (2 Strings), Netz,
  Batterie, Hausverbrauch, Energie, Start/Stop
- **Solplanet / AISWEI** (ASW-Gen-Serie): direkte Adressierung, Read Input Register.
  PV (3 Strings), Batterie, Temperatur, Energie
- **Kostal** (PLENTICORE plus Gen. 1): direkte Adressierung, native Float32-Register.
  PV (3 DC-Eingänge), Netz, Batterie, Meter, Hausverbrauch nach Quelle, Energie, Gerätename

Im Zuge dessen Bugfix vor dem ersten Test gefunden: Kostal `bat_soc` nutzte Float-Typ mit dem
Integer-Profil `~Battery.100` (derselbe Fehlertyp wie zuvor bei GoodWe/Sungrow).

## 0.5.0-beta.1 (2026-07-16)

Gegenprüfung gegen unabhängige Quellen: die [OpenEMS](https://github.com/OpenEMS/openems)-
Referenzimplementierung (offizielle SunSpec-Modelldefinitionen, GoodWe/Fronius/SMA-Treiber)
und community-getestete Modbus-Vorlagen aus dem
[IP-Symcon-Forum](https://community.symcon.de/c/symcon/vorlagen-modbus/86).

- **GoodWe**: alle Kernregister 1:1 gegen OpenEMS und eine unabhängige, community-getestete
  Vorlage bestätigt — höchste Vertrauensstufe aller Treiber
- **Kritischer Bugfix Fronius**: SunSpec-Feldoffsets waren im von Fronius empfohlenen
  Standardpfad (Float-Modell 111/112/113) fast durchgehend falsch (ac_power, status, grid_volt,
  grid_freq, e_total betroffen), ebenso im Int+SF-Fallback und im Meter-Modell. Gegen die
  offizielle SunSpec-Modelldefinition korrigiert. Seriennummer-Offset im Common Block ebenfalls
  falsch (48 statt 40)
- **SMA komplett neu**: Treiber von SMA-eigenen nativen Registern auf SunSpec umgestellt
  (wie von OpenEMS für den SMA Sunny Tripower verwendet) — schließt die vorher bewusst offene
  AC-/Netzlücke vollständig und nutzt dieselben verifizierten Feldoffsets wie Fronius
- **SolaX**: Grundregister (Netz, PV1/2, Meter, inkl. eigenständig hergeleiteter T-Phasen-
  Adresse) durch unabhängige Community-Vorlage bestätigt. Batterie-Detailregister aus der
  Vorlage bewusst noch nicht übernommen, da sie aus einer anderen Protokollversion
  (Hybrid-G4 ModbusRTU statt ESS/TCP) stammen — Vermischen zweier Protokollversionen ohne
  Auswahlmechanismus wäre riskant, folgt als eigener Schritt

## 0.4.3-beta.1 (2026-07-15)

- **Kritischer Bugfix** (gemeldet von einem Beta-Tester, Sungrow SH 6.0 RT): Off-by-one-
  Adressfehler in Sungrow und Solis behoben — beide Treiber lasen jedes Register einen Schritt
  zu früh (Adresse = dokumentierte Registernummer − 1), was zu offensichtlichem Datenmüll führte
  (z. B. `bat_power = -52690945`). Ursache war fälschlich übernommenes SunSpec-Adressierungs-
  schema; Sungrow und Solis adressieren direkt ohne Offset
- Discovery-Erkennung deutlich robuster gegen Fehlzuordnungen: für GoodWe/Sungrow/Solis/SolaX
  wird jetzt zusätzlich zum Primärregister das jeweilige Seriennummer-/Modellregister auf
  plausiblen ASCII-Text geprüft, für Growatt zusätzlich die Temperatur auf Plausibilität
  (real gemeldet: ein Janitza PAC2200 wurde als SolaX erkannt, ein Modbus-RTU/TCP-Konverter
  als GoodWe)

## 0.4.2-beta.1 (2026-07-14)

- **Kritischer Bugfix** (gemeldet von einem Beta-Tester): Für alle Hersteller außer GoodWe
  fehlten sämtliche Datenpunkt-Gruppen-Properties bei der Instanzerstellung
  ("Eigenschaft GroupPV/GroupBat nicht gefunden"), da `Create()` nur die Properties des zum
  Erstellungszeitpunkt aktiven Default-Treibers (GoodWe) registrierte. Jetzt werden die
  Properties aller Treiber registriert, unabhängig vom gewählten Hersteller

## 0.4.1-beta.1 (2026-07-14)

- Beta-Hinweis mit Link zum Symcon-Forum-Thread in beiden Modulen (InverterHub,
  InverterHubDiscovery) ergänzt, dismissable per „Nicht mehr anzeigen"

## 0.4.0-beta.1 (2026-07-14)

- Freie Namens-Vorlage für neu angelegte Instanzen im Discovery-Modul: leer = Default
  „Hersteller + laufende Nummer" (z. B. „GoodWe 1", „GoodWe 2"), oder eigenes Muster mit
  Platzhaltern `{hersteller}` `{ip}` `{unitid}` `{nr}` (z. B. `{hersteller} Dach ({ip})`)

## 0.3.1 (2026-07-14)

- Bug: bereits über die Discovery angelegte Instanzen erschienen beim erneuten Öffnen des
  Suchformulars wieder als „Kein(e)" statt mit ihrer InstanzID — Ergebnisliste wird jetzt gegen
  vorhandene InverterHub-Instanzen (Host + Unit-ID) abgeglichen
- Sprechenderer Instanzname bei der Erstellung („GoodWe Wechselrichter (192.168.2.102)")

## 0.3.0 (2026-07-14)

- Custom Actions auf Steuervariablen werden per einmaligem Kurz-Timer nach `ApplyChanges`
  gesetzt statt synchron währenddessen — der bisherige Ansatz („erst ab dem zweiten
  ApplyChanges") griff nicht, wenn eine Instanz inklusive Konfiguration in einem einzigen
  synchronen Ablauf entsteht (genau das tut das Discovery-Modul beim Erstellen)
- Discovery-Layout vereinfacht: Suchfelder normal gestapelt in einem Panel, Ergebnistabelle in
  einem eigenen, vollbreiten Panel darunter (statt zweispaltig mit viel Leerraum)
- Modultyp von `InverterHubDiscovery` auf `5` (Discovery) korrigiert — Instanz erscheint jetzt
  unter „Discovery Instanzen" statt „Splitter Instanzen"

## 0.2.0 (2026-07-14)

- Neues Modul `InverterHubDiscovery` (Configurator): durchsucht einen IP-Bereich per
  nicht-blockierendem Parallel-Scan nach offenem Modbus-TCP-Port 502, erkennt den Hersteller
  anhand weniger dokumentierter Standard-Unit-IDs pro Hersteller über ein charakteristisches
  Register, legt per Klick eine `InverterHub`-Instanz mit vorausgefüllter IP-Adresse/Unit-ID/
  Hersteller an. Start-/End-IP werden anhand des eigenen Netzwerks vorbelegt
  (`create`-Objekt-Struktur gemäß IP-Symcon-Konfigurator-Schema)
- Icons vor den ExpansionPanel-Überschriften in `InverterHub` (🔌 Verbindung, ⏱️ Polling,
  📊 Datenpunkte, 📖 Dokumentation & Hilfe)

## 0.1.1 (2026-07-14)

- Sechs weitere Hersteller-Treiber ergänzt: **Sungrow** (SH-Hybrid, Read Input Register),
  **Solis** (Hybrid-Serie, 33000er-Register), **Growatt** (TL-X/TL3-X/MOD/MIX/SPH/WIT-
  Basisbereich, durchnummerierte Register mit H/L-Paaren), **SolaX** (Hinweis: nur über
  zusätzliches Monitoring-Modul per Modbus TCP erreichbar, Wechselrichter selbst spricht nur
  RTU), **SMA** (PV/Energie/Temperatur/Status; AC-/Netzregister bewusst ausgespart, da beim
  Erstellen nicht vollständig dokumentiert vorliegend), **Fronius** (reine
  SunSpec-Implementierung mit Laufzeit-Modell-Discovery ab Basisregister 40000 statt fester
  Adressen, wie von Fronius dokumentiert gefordert)
- `ModbusTcpClient` um `readInput()` (Read Input Register, FC 0x04) und `readFloat32()`
  (IEEE-754, SunSpec-Konvention) erweitert

## 0.1.0 (2026-07-14)

Erstveröffentlichung.

- `InverterHub`: generisches Wechselrichter-Modul mit austauschbaren Hersteller-Treibern
  (`InverterDriverInterface`) statt eines separaten Moduls pro Hersteller. Gemeinsame
  Modbus-TCP-Grundfunktionen in `ModbusTcpClient`
- Erster Treiber **GoodWe**, 1:1 aus dem produktiven GoodweET-Modul portiert (inklusive
  dokumentierter Firmware-Quirks, BMS-Sonderlogik, Inselerkennung) — das ursprüngliche
  GoodweET-Modul läuft unverändert und unabhängig weiter
- Registeradressen je Variable im Beschreibungsfeld sichtbar (Objekt-Manager)
- Dokumentations-Panel „📖 Dokumentation & Hilfe" in der Instanzkonfiguration
- Diverse Kompatibilitätskorrekturen während der Erstinbetriebnahme: PHP-Type-Hints auf die von
  IP-Symcon geforderten Skalartypen (bool/int/float/string) für öffentliche, gebridgte
  Instanzmethoden reduziert; Protected-Method-Zugriff aus Treiber-Klassen auf
  `ReadPropertyBoolean()` behoben (öffentlicher Wrapper `GetPropBool()`);
  `soc`/`bat1_soc`/`bat2_soc`/`bat1_soh`/`bat2_soh` von Float auf Integer korrigiert
  (`~Battery.100`/`~Intensity.100` sind Integer-Profile)

---

Bekannte Lücken: SolaX-BAT-Detailregister (Einzelspannung/-strom) nicht abgedeckt, Solis nur
Hybrid-Serie, SMA ohne AC-/Netzmessung, Fronius ohne Batterie-Monitoring (siehe README).
