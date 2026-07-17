# InverterHub

IP-Symcon-Modul, das Wechselrichter verschiedener Hersteller direkt per **Modbus TCP** ausliest
und steuert — ein generisches Treiber-Framework statt eines Moduls pro Hersteller.

**Status: Beta.** Die Register-Zuordnungen basieren auf den öffentlich verfügbaren
Modbus-Protokolldokumenten der Hersteller und wurden, soweit möglich, gegen reale Anlagen
geprüft (aktuell GoodWe live verifiziert) sowie gegen unabhängige Quellen gegengeprüft — die
Referenzimplementierung [OpenEMS](https://github.com/OpenEMS/openems) für Register-/
Feldoffsets (GoodWe, Fronius, SMA) und von echten Nutzern im
[IP-Symcon-Forum](https://community.symcon.de/c/symcon/vorlagen-modbus/86) geteilte
Modbus-Vorlagen (GoodWe, SolaX, SolarEdge, Deye, Solplanet, Kostal). Rückmeldungen zu
falschen/fehlenden Werten sind willkommen — bitte mit Hersteller, Modell und betroffenem
Register melden.

## Unterstützte Hersteller

| Hersteller | Umfang | Anmerkung |
|---|---|---|
| **GoodWe** | PV (3 MPPT-Tracker), Netz, Batterie 1+2, Meter, Energie, Backup/Insel, EMS-Steuerung | Live verifiziert, produktiv im Einsatz |
| **Sungrow** | PV (4 MPPT), Netz, Batterie, Meter, Energie, Backup, Start/Stop | SH-Hybrid-Serie |
| **Solis** | PV (4 Strings), Netz, Batterie, Meter, Energie | Nur Hybrid-Serie (33000er-Register); reine String-Wechselrichter (3000er) noch nicht unterstützt |
| **Growatt** | PV (3 Strings), Netz, Energie, Temperatur, Fehlercodes | Deckt den über TL-X/TL3-X/MOD/MIX/SPH/WIT gemeinsamen Basisregisterbereich ab |
| **SolaX** | PV (6 Strings), Netz ein-/dreiphasig, Batterie-Systemwerte, Meter/CT | **Wichtig:** Der Wechselrichter spricht nur Modbus RTU. Modbus TCP läuft ausschließlich über ein zusätzliches SolaX-Monitoring-Modul (Pocket WiFi/LAN) als Gateway — dessen IP-Adresse eintragen, nicht die des Wechselrichters |
| **SolarEdge** | PV Gesamtleistung, Netz, Meter, Energie, Temperatur, Status, Gerätename/Seriennummer | Reines SunSpec, dieselbe Laufzeit-Discovery wie Fronius/SMA |
| **Deye** | PV (2 Strings), Netz, Batterie, Hausverbrauch, Energie, Start/Stop-Steuerung | SG04LP3-Serie, Vorlage von einem 8K-SG04LP3 getestet |
| **Solplanet / AISWEI** | PV (3 Strings), Batterie, Temperatur, Energie | ASW-Gen-Serie |
| **Kostal** | PV (3 DC-Eingänge), Netz, Batterie, Meter, Hausverbrauch nach Quelle, Energie | Nur PLENTICORE plus Generation 1 getestet — andere Generationen/Leistungsklassen ungeprüft. **Wichtig:** Kostal nutzt standardmäßig Port **1502**, nicht 502 — beim Anlegen der Instanz ggf. manuell eintragen. |
| **SMA** | PV Gesamtleistung, Netz, Meter, Energie, Temperatur, Status, Gerätename/Seriennummer | Reine SunSpec-Implementierung mit Laufzeit-Discovery, wie von OpenEMS für SMA Sunny Tripower verwendet |
| **Fronius** | PV Gesamtleistung, Netz, Meter, Energie, Temperatur, Status, Gerätename/Seriennummer | Reine SunSpec-Implementierung mit Laufzeit-Discovery (keine festen Registeradressen, siehe unten) |

Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch zum Abgleich mit dem Herstellerhandbuch oder für eigene Skripte.

## Module in diesem Repository

### InverterHub

Die eigentliche Datenauslese-Instanz. Ein Modul, ein `Manufacturer`-Auswahlfeld — je nach
gewähltem Hersteller werden die passenden Datenpunkt-Gruppen (Checkboxen) und Register
freigeschaltet. Architektur:

- **`ModbusTcpClient`** — gemeinsame Modbus-TCP-Grundfunktionen (Read Holding/Input Register,
  Write Single/Multiple, Datentyp-Hilfsfunktionen), von allen Treibern genutzt.
- **`InverterDriverInterface`** — Vertrag, den jeder Hersteller-Treiber erfüllt (Basisvariablen,
  optionale Gruppen, Profile, `readFast`/`readSlow`/`readDeviceInfo`/`writeControl`).
- **Ein Treiber je Hersteller** (`GoodweDriver`, `SungrowDriver`, `SolisDriver`, `GrowattDriver`,
  `SolaxDriver`, `SmaDriver`, `FroniusDriver`) — kapselt die herstellerspezifischen
  Registeradressen, Skalierungsfaktoren und Eigenheiten.

Einrichtung: Instanz anlegen, Hersteller wählen, IP-Adresse (und bei Bedarf Port/Unit-ID)
eintragen, gewünschte Datenpunkt-Gruppen aktivieren, übernehmen.

**Hauslastzähler (optional):** Unter „Hauslastzähler (optional)" lässt sich eine bereits
vorhandene Variable mit real gemessener Hauslast auswählen (z. B. ein Shelly am
Hausanschluss). Die reine PV/Netz/Batterie-Bilanzschätzung berücksichtigt Wechselrichter-
Eigenverbrauch und Leitungsverluste nicht — mit einem echten Zähler zeigt `InverterHubTile`
die genauere Last sowie die Differenz als eigenen „Wandlungsverluste"-Kreis.

### InverterHubDiscovery

Ein **Configurator**-Modul, das einen IP-Bereich im lokalen Netz nach Wechselrichtern auf
Modbus-TCP-Port 502 durchsucht:

1. Start- und End-IP eintragen (wird beim Anlegen anhand des eigenen Netzwerks vorbelegt,
   bleibt aber änderbar), optional eine Namens-Vorlage für neu anzulegende Instanzen.
2. „Netzwerk durchsuchen" klicken — nicht-blockierender Parallel-Scan auf Port 502.
3. Für jede offene IP wird der Hersteller anhand weniger dokumentierter Standard-Unit-IDs und
   eines charakteristischen Registers pro Hersteller erkannt (kein voller 1-247-Scan).
4. Treffer erscheinen in der Ergebnistabelle — Klick auf „Erstellen" legt eine
   `InverterHub`-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an.

**IPs ignorieren:** Adressen in dieser Liste werden beim Scan komplett übersprungen — gedacht
für RTU/TCP-Konverter und andere Modbus-Geräte, die sonst fälschlich als Wechselrichter in
der Ergebnisliste erscheinen (solche Geräte leiten Modbus-Anfragen an den dahinterliegenden
Bus weiter und antworten daher mit plausiblen Werten; zuverlässig unterscheiden lässt sich
das nicht). Mehrere IPs Komma-getrennt.

**Namens-Vorlage:** leer lassen für den Standard „Hersteller + laufende Nummer" (z. B.
„GoodWe 1", „GoodWe 2"), oder ein eigenes Muster mit den Platzhaltern `{hersteller}` `{ip}`
`{unitid}` `{nr}` eintragen (z. B. `{hersteller} Dach ({ip})`).

**Bekannte Einschränkung:** Filter/Aktualisieren (oberhalb) und Erstellen/Alle erstellen/
Zielkategorie-Auswahl (unterhalb) der Ergebnistabelle sind fester Bestandteil der nativen
IP-Symcon-Konfigurator-Ansicht — ihre Position und ein „einzeln als gesehen markieren" lassen
sich modulseitig nicht beeinflussen bzw. ergänzen (IP-Symcon-API-Grenze, keine Dokumentation
dafür vorhanden).

### InverterHubTile

Energiefluss-Kachel (Visualisierung) für eine InverterHub-Instanz, unabhängig vom Hersteller.
Die **Hauslast** sitzt im Zentrum, alle übrigen Größen (Solar, Batterie, Netz, optional
Wandlungsverluste sowie frei konfigurierte Verbraucher) werden gleichmäßig **radial** darum
verteilt — in der Reihenfolge Solar (oben), Batterie (rechts), Verbraucher, Netz (unten),
Verluste (links). Fehlt ein Datenpunkt, bleibt die Anordnung ausgewogen, statt eine Lücke zu
hinterlassen. Kreisgröße und -abstand werden aus der Knotenzahl berechnet, sodass sich auch
bei vielen Verbrauchern nie Kreise überlappen und die Kachel ihre Größe behält.

**Weitere Verbraucher (optional):** Im Panel „Weitere Verbraucher" lassen sich **beliebig
viele** zusätzliche Verbraucher als Tabelle pflegen — je Zeile **Art**, **Bezeichnung** und
**Leistungs-Variable** (Watt). Sie kommen nicht aus dem Wechselrichter, sondern werden aus
vorhandenen Variablen gespeist und erscheinen als eigene Kreise. Verfügbare Arten (bestimmen
das Icon): Wallbox, Wärmepumpe, Klimaanlage, Pool-Wärmepumpe, Pool-Pumpe, Sauna, Warmwasser,
Trockner, Sonstiger Verbraucher. Mehrere Zeilen derselben Art sind möglich (z. B. zwei
Wallboxen „Garage" und „Carport"); eine leere Bezeichnung fällt auf die Vorgabe der Art
zurück.

**Wallboxen mit Fahrzeug-Ladestand:** Eine Wallbox wird als **Auto** dargestellt, das – wie das
Batteriesymbol – den Ladestand des gerade angeschlossenen Fahrzeugs als Füllung samt
Prozentwert zeigt; ohne Fahrzeug bleibt nur der Umriss. Der Name des erkannten Fahrzeugs steht
als Zusatzzeile im Kreis.

Dafür gibt es die Tabelle **Fahrzeuge** (Bezeichnung, Ladestand-Variable, Verbunden-Bedingung)
sowie je Wallbox-Zeile eine eigene Verbunden-Bedingung. Eine Bedingung besteht aus **Variable +
Vergleich + Wert**, weil jede Quelle das Einstecken anders meldet:

| Beispiel | Typ | Bedingung |
|---|---|---|
| „Ladeportklappe offen" (Fahrzeug) | Boolean | ist gesetzt |
| „Ladekabeltyp" (Fahrzeug) | Text, leer = kein Kabel | ist gesetzt |
| „Kabel-Leistungsfähigkeit" (go-e) | Integer, 0 = kein Kabel | ist gesetzt |

**Welches Auto steht an welcher Wallbox?** Das ermittelt das Modul selbst — ein Datenpunkt, der
das benennt, wird *nicht* benötigt (die wenigsten Anlagen haben so etwas). Beim Einstecken
wechseln Wallbox und Fahrzeug jedes für sich auf „verbunden", und zwar praktisch gleichzeitig.
Das Modul vergleicht dafür die Zeitpunkte der letzten **Wertänderung** (IP-Symcon führt die
ohnehin mit) und ordnet die zeitlich am besten passenden Paare eindeutig zu. Bei zwei Autos an
zwei Wallboxen landet damit jedes dort, wo es tatsächlich eingesteckt wurde. Das Zeitfenster
ist einstellbar (Vorgabe 300 s; 0 = ohne Begrenzung). Bei genau einer Wallbox und genau einem
Fahrzeug ist die Lage ohnehin eindeutig — dort darf die Verbunden-Bedingung auch fehlen.

Die Farben sind semantisch fest vergeben: Solar = Sonnengelb, Netz = Grün bei Einspeisung/Rot
bei Bezug, Batterie = Blau, Verluste = Grau, Hauslast = weicher Grün-Rot-Verlauf je nach
Anteil aus Netzbezug vs. PV/Batterie. Zusätzliche Verbraucher haben je Art eine eigene Farbe
(Wärme in Feuertönen, Kühlung/Wasser in Türkis, Fahrzeuge in Violett) und lassen sich je Zeile
auch frei einfärben.

**Energiefluss:** Zwischen Hauslast und jedem Knoten läuft eine Speiche mit glimmendem Leiter,
darauf wandern Dreiecke in Flussrichtung, begleitet von wabernden Blitzen. Die Richtung folgt
dem Vorzeichen (Netz: Bezug zum Haus / Einspeisung nach außen; Batterie: Laden nach außen /
Entladen zum Haus), das Tempo der Leistung.

Kreise mit nennenswertem Leistungsfluss erscheinen groß, farbig und plastisch (Münz-Optik mit
Wölbung, Kantenanschliff, Glanzlicht und geprägten Icons/Werten) samt Corona, deren Stärke mit
der Leistung wächst (0 W = keine, 40 kW = maximal). Kreise ohne Fluss treten klein, grau und
flach zurück; der Wechsel läuft gleitend in einstellbarer Zeit ab.

Da nicht jeder Treiber dieselben Datenpunkte liefert, entfällt ein Kreis, wenn die zugehörige
Größe bei der gewählten Quelle fehlt (z. B. keine Netzmessung bei Growatt, keine Batterie bei
SMA/Fronius/SolarEdge), statt falsche Werte zu zeigen. Ist in der Quell-Instanz ein
Hauslastzähler konfiguriert, erscheint zusätzlich der „Verluste"-Kreis (Differenz zwischen
Bilanzschätzung und echtem Zähler).

Hintergrundfarbe, Schriftart und die Übergangszeit für den gleitenden Wechsel aktiv/inaktiv
sind über die Instanzkonfiguration anpassbar; die Kachel skaliert vollständig automatisch mit
der Widget-Größe.

Einrichtung: Kachel-Instanz anlegen, unter „Datenquelle" die gewünschte InverterHub-Instanz
auswählen.

## Fronius und SMA: Hinweis zur SunSpec-Discovery

Beide Hersteller sprechen den offenen SunSpec-Standard statt eigener Register (bei Fronius
von Fronius selbst so dokumentiert — Registeradressen sind **nicht konstant** und hängen von
der jeweiligen Modellkette ab; bei SMA folgt dieses Modul dem Vorbild von OpenEMS, das den
SMA Sunny Tripower ebenfalls rein über SunSpec anspricht). `FroniusDriver` und `SmaDriver`
durchlaufen deshalb bei jedem Lesezyklus die Modellkette ab Basisregister 40000 (Common Block,
dann Model-ID + Länge je Block), statt feste Adressen zu verwenden. Das ist etwas langsamer als
bei den anderen Herstellern, aber der zuverlässigste Weg.

## Installation

Über die IP-Symcon Modulverwaltung „Hinzufügen" mit der URL dieses Repositories:

```
https://github.com/DG65/InverterHub
```

Für den Beta-Kanal den Zweig `beta` auswählen.

## Mitwirken / Fehler melden

Rückmeldungen zu falschen Registerwerten, fehlenden Datenpunkten oder neuen unterstützten
Modellen gerne als Issue auf GitHub. Besonders hilfreich: Hersteller, Modellbezeichnung,
betroffenes Register/Ident und beobachteter vs. erwarteter Wert.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
