# Changelog

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
